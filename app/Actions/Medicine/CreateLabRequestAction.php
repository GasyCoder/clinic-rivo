<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\LabRequest;
use App\Models\User;
use App\Services\Billing\ClinicalActBiller;
use App\Services\Billing\ParaclinicalBillingRelease;
use App\Services\Billing\PlannedServiceBilling;
use App\Support\Hospitalization\StayOrderContext;
use App\Support\ParaclinicalRequestGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The DEMANDE a doctor makes for one or more analyses — Laboratoire then
 * tracks sample/analysis/result on its own, in parallel with the ongoing
 * Médecine consultation (unlike CareOrder, this never completes the
 * Médecine orientation: the doctor is not physically handing the patient
 * off, just requesting a report).
 */
class CreateLabRequestAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly ClinicalActBiller $biller,
        private readonly PlannedServiceBilling $plannedBilling,
        private readonly ResolveConsultationStepAction $resolveStep,
    ) {}

    /**
     * @param  array<int, array{catalog_item_uuid: string}>  $items
     */
    public function execute(
        Consultation $consultation,
        array $items,
        ?string $notes,
        User $actor,
    ): LabRequest {
        return DB::transaction(function () use ($consultation, $items, $notes, $actor): LabRequest {
            $lockedConsultation = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $medicineOrientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($lockedConsultation->episode_orientation_id);

            if ($medicineOrientation->destination_module !== CatalogModule::Medicine
                || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'lab_request' => 'Cette consultation n’est plus active.',
                ]);
            }

            $labRequest = $this->write(
                $medicineOrientation->episode,
                $lockedConsultation,
                null,
                $medicineOrientation,
                CatalogModule::Medicine,
                'Analyses demandées en consultation.',
                $items,
                $notes,
                $actor,
            );

            $lockedConsultation->update(['decision' => ConsultationDecision::LaboratoryTests]);

            // ADR-105 — la demande transmise EST la résolution de l'étape :
            // le médecin n'a plus rien à y faire, et lui demander de la
            // « valider » ensuite bloquait la clôture sur un clic sans
            // objet. Amende la règle « jamais en effet de bord » de
            // l'ADR-076, qui visait une saisie en cours, pas un ordre déjà
            // parti à un autre service.
            $this->resolveStep->completeParaclinicalFromRequest($lockedConsultation, $actor);

            return $labRequest->fresh(['items']);
        });
    }

    /**
     * ADR-162 — la même demande, écrite depuis le séjour : le patient est au
     * lit, aucune consultation n'est ouverte ni fabriquée. Facturation,
     * doublons et orientation vers le Laboratoire sont ceux d'une demande de
     * consultation.
     *
     * @param  array<int, array{catalog_item_uuid: string}>  $items
     */
    public function executeForStay(HospitalStay $stay, array $items, ?string $notes, User $actor): LabRequest
    {
        return DB::transaction(function () use ($stay, $items, $notes, $actor): LabRequest {
            $context = StayOrderContext::lock($stay, 'lab_request');

            return $this->write(
                $context->episode,
                null,
                $context->stay,
                $context->orientation,
                CatalogModule::Hospitalization,
                'Analyses demandées pendant l’hospitalisation.',
                $items,
                $notes,
                $actor,
            );
        });
    }

    /** @param  array<int, array{catalog_item_uuid: string}>  $items */
    private function write(
        Episode $episode,
        ?Consultation $consultation,
        ?HospitalStay $stay,
        EpisodeOrientation $source,
        CatalogModule $sourceModule,
        string $orientationReason,
        array $items,
        ?string $notes,
        User $actor,
    ): LabRequest {
        $catalogUuids = collect($items)->pluck('catalog_item_uuid')->unique();
        $catalogItems = CatalogItem::query()
            ->whereIn('uuid', $catalogUuids)
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Laboratory->value)
            ->lockForUpdate()
            ->get()
            ->keyBy('uuid');

        if ($catalogItems->count() !== $catalogUuids->count()) {
            throw ValidationException::withMessages([
                'items' => 'Une analyse sélectionnée n’est plus disponible.',
            ]);
        }

        // Sous le verrou déjà posé sur la consultation : deux envois
        // simultanés du même examen ne peuvent pas passer tous les deux.
        ParaclinicalRequestGuard::ensureNoActiveDuplicate(
            $consultation ?? $stay,
            $catalogItems,
            'labRequests',
            'lab_request',
        );

        $labOrientation = $this->createOrientation->execute(
            $episode,
            $sourceModule,
            CatalogModule::Laboratory,
            $actor,
            $orientationReason,
        );

        $labRequest = LabRequest::query()->create([
            'episode_id' => $episode->getKey(),
            'consultation_id' => $consultation?->getKey(),
            'hospital_stay_id' => $stay?->getKey(),
            'source_orientation_id' => $source->getKey(),
            'lab_orientation_id' => $labOrientation->getKey(),
            'requested_by' => $actor->getKey(),
            'notes' => $notes,
            'requested_at' => now(),
        ]);

        foreach ($items as $item) {
            $catalogItem = $catalogItems->get($item['catalog_item_uuid']);

            $line = $labRequest->items()->create([
                'catalog_item_id' => $catalogItem->getKey(),
                'catalog_item_code_snapshot' => $catalogItem->code,
                'catalog_item_name_snapshot' => $catalogItem->name,
            ]);

            // ADR-105 — l'analyse rejoint le compte du patient dès sa
            // demande, comme lorsque la Réception la sélectionne à
            // l'arrivée (ADR-068). Un échec de facturation ne bloque
            // jamais la demande : elle est déjà partie au Laboratoire.
            // ADR-109 — si la Réception a déjà planifié et facturé cet
            // examen à l'arrivée (ADR-068), la demande du médecin rattache
            // cette prestation au lieu d'en créer une seconde : le patient
            // ne paie pas deux fois la même échographie.
            $billable = $this->plannedBilling->unconsumedFor($episode, $catalogItem)
                ?? $this->biller->bill(
                    $episode,
                    $catalogItem,
                    ParaclinicalBillingRelease::ownKey($line),
                    $actor,
                    $line,
                );

            if ($billable) {
                $line->update(['billable_item_id' => $billable->getKey()]);
            }
        }

        return $labRequest->fresh(['items']);
    }
}
