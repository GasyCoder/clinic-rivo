<?php

namespace App\Actions\Reception;

use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeServiceRequest;
use App\Models\LabRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Creates the operational Laboratory request for analyses chosen at Reception. */
class CreateReceptionLabRequestAction
{
    /** @param Collection<int, EpisodeServiceRequest> $serviceRequests */
    public function execute(
        Episode $episode,
        Collection $serviceRequests,
        EpisodeOrientation $labOrientation,
        User $actor,
    ): ?LabRequest {
        $laboratoryRequests = $serviceRequests->filter(
            fn (EpisodeServiceRequest $request) => $request->module === CatalogModule::Laboratory
                && $request->routing_mode === ReceptionRoutingMode::LaboratoryDirect,
        );

        if ($laboratoryRequests->isEmpty()) {
            return null;
        }

        $existing = LabRequest::query()
            ->where('episode_id', $episode->getKey())
            ->whereNull('consultation_id')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            $existingIds = $existing->items()->pluck('catalog_item_id')->sort()->values();
            $requestedIds = $laboratoryRequests->pluck('catalog_item_id')->unique()->sort()->values();

            if ($existingIds->all() !== $requestedIds->all()) {
                throw ValidationException::withMessages([
                    'catalog_lines' => 'La demande Laboratoire de ce passage existe déjà avec une autre sélection.',
                ]);
            }

            return $existing->load('items');
        }

        $labRequest = LabRequest::query()->create([
            'episode_id' => $episode->getKey(),
            'consultation_id' => null,
            'source_orientation_id' => null,
            'lab_orientation_id' => $labOrientation->getKey(),
            'requested_by' => $actor->getKey(),
            'notes' => 'Analyses sélectionnées à la Réception.',
            'requested_at' => now(),
        ]);

        foreach ($laboratoryRequests as $request) {
            $labRequest->items()->create([
                'catalog_item_id' => $request->catalog_item_id,
                'catalog_item_code_snapshot' => $request->catalog_code,
                'catalog_item_name_snapshot' => $request->designation,
            ]);
        }

        return $labRequest->fresh('items');
    }
}
