<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\LabRequest;
use App\Models\User;
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

                $labRequest->items()->create([
                    'catalog_item_id' => $catalogItem->getKey(),
                    'catalog_item_code_snapshot' => $catalogItem->code,
                    'catalog_item_name_snapshot' => $catalogItem->name,
                ]);
            }

            $lockedConsultation->update(['decision' => ConsultationDecision::LaboratoryTests]);

            return $labRequest->fresh(['items']);
        });
    }
}
