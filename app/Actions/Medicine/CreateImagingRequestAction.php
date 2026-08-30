<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequest;
use App\Models\User;
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

                $imagingRequest->items()->create([
                    'catalog_item_id' => $catalogItem->getKey(),
                    'catalog_item_code_snapshot' => $catalogItem->code,
                    'catalog_item_name_snapshot' => $catalogItem->name,
                ]);
            }

            return $imagingRequest->fresh(['items']);
        });
    }
}
