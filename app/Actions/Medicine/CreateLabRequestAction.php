<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Medicine\ResolveConsultationStepAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\User;
use App\Services\Billing\ClinicalActBiller;
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

            $episode = $medicineOrientation->episode;
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
                $lockedConsultation,
                $catalogItems,
                'labRequests',
                'lab_request',
            );

            $labOrientation = $this->createOrientation->execute(
                $episode,
                CatalogModule::Medicine,
                CatalogModule::Laboratory,
                $actor,
                'Analyses demandées en consultation.',
            );

            $labRequest = LabRequest::query()->create([
                'episode_id' => $episode->getKey(),
                'consultation_id' => $lockedConsultation->getKey(),
                'source_orientation_id' => $medicineOrientation->getKey(),
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
                $billable = $this->biller->bill(
                    $episode,
                    $catalogItem,
                    'lab_request_item:'.$line->uuid,
                    $actor,
                    $line,
                );

                if ($billable) {
                    $line->update(['billable_item_id' => $billable->getKey()]);
                }
            }

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
}
