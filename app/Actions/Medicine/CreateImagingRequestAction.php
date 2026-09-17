<?php

namespace App\Actions\Medicine;

use App\Actions\Medicine\ResolveConsultationStepAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequest;
use App\Models\User;
use App\Services\Billing\ClinicalActBiller;
use App\Support\ParaclinicalRequestGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The DEMANDE a doctor makes for one or more imaging exams (ECG,
 * échographie). Unlike CreateLabRequestAction, no cross-module
 * EpisodeOrientation is created: no imaging workspace exists yet, so
 * DEMANDE and RESULTAT both stay owned by Médecine (ADR-030 already treats
 * ECG/échographie as Médecine's own remit for MEDICINE_DIRECT arrivals).
 */
class CreateImagingRequestAction
{
    public function __construct(
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
    ): ImagingRequest {
        return DB::transaction(function () use ($consultation, $items, $notes, $actor): ImagingRequest {
            $lockedConsultation = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $medicineOrientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($lockedConsultation->episode_orientation_id);

            if ($medicineOrientation->destination_module !== CatalogModule::Medicine
                || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'imaging_request' => 'Cette consultation n’est plus active.',
                ]);
            }

            $episode = $medicineOrientation->episode;
            $catalogUuids = collect($items)->pluck('catalog_item_uuid')->unique();
            $catalogItems = CatalogItem::query()
                ->whereIn('uuid', $catalogUuids)
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Imaging->value)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            if ($catalogItems->count() !== $catalogUuids->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Un examen sélectionné n’est plus disponible.',
                ]);
            }

            // Sous le verrou déjà posé sur la consultation : deux envois
            // simultanés du même examen ne peuvent pas passer tous les deux.
            ParaclinicalRequestGuard::ensureNoActiveDuplicate(
                $lockedConsultation,
                $catalogItems,
                'imagingRequests',
                'imaging_request',
            );

            $imagingRequest = ImagingRequest::query()->create([
                'episode_id' => $episode->getKey(),
                'consultation_id' => $lockedConsultation->getKey(),
                'source_orientation_id' => $medicineOrientation->getKey(),
                'requested_by' => $actor->getKey(),
                'notes' => $notes,
                'requested_at' => now(),
            ]);

            foreach ($items as $item) {
                $catalogItem = $catalogItems->get($item['catalog_item_uuid']);

                $line = $imagingRequest->items()->create([
                    'catalog_item_id' => $catalogItem->getKey(),
                    'catalog_item_code_snapshot' => $catalogItem->code,
                    'catalog_item_name_snapshot' => $catalogItem->name,
                ]);

                // ADR-105 — l'ECG ou l'échographie rejoint le compte du
                // patient dès sa demande, comme à la Réception (ADR-068).
                $billable = $this->biller->bill(
                    $episode,
                    $catalogItem,
                    'imaging_request_item:'.$line->uuid,
                    $actor,
                    $line,
                );

                if ($billable) {
                    $line->update(['billable_item_id' => $billable->getKey()]);
                }
            }

            // ADR-105 — la demande transmise résout l'étape Paraclinique.
            $this->resolveStep->completeParaclinicalFromRequest($lockedConsultation, $actor);

            return $imagingRequest->fresh(['items']);
        });
    }
}
