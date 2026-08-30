<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Surgery\CreateSurgicalRequestAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\SurgicalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Médecine creates a structured DEMANDE only — diagnostic, indication and
 * envisaged intervention — never the full surgical dossier (consultation
 * pré-anesthésique, bloc, etc.), which stays owned by the Chirurgie
 * workspace once it accepts the resulting orientation.
 */
class CreateSurgicalReferralAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly CreateSurgicalRequestAction $createSurgicalRequest,
    ) {}

    public function execute(
        Consultation $consultation,
        string $catalogItemUuid,
        string $diagnostic,
        ?string $indication,
        string $priority,
        ?string $notes,
        User $actor,
    ): SurgicalRequest {
        return DB::transaction(function () use ($consultation, $catalogItemUuid, $diagnostic, $indication, $priority, $notes, $actor): SurgicalRequest {
            $lockedConsultation = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $medicineOrientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($lockedConsultation->episode_orientation_id);

            if ($medicineOrientation->destination_module !== CatalogModule::Medicine
                || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'surgical_request' => 'Cette consultation n’est plus active.',
                ]);
            }

            $catalogItem = CatalogItem::query()
                ->where('uuid', $catalogItemUuid)
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Surgery->value)
                ->lockForUpdate()
                ->first();

            if (! $catalogItem) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'Cette intervention n’est plus disponible.',
                ]);
            }

            $episode = $medicineOrientation->episode;

            $this->createOrientation->execute(
                $episode,
                CatalogModule::Medicine,
                CatalogModule::Surgery,
                $actor,
                'Demande de chirurgie depuis la consultation.',
            );

            $surgicalRequest = $this->createSurgicalRequest->execute($episode, [
                'catalog_item_id' => $catalogItem->getKey(),
                'procedure_name' => $catalogItem->name,
                'procedure_details' => $indication,
                'notes' => trim("Diagnostic : {$diagnostic}\nPriorité : {$priority}".($notes ? "\n{$notes}" : '')),
            ]);

            $lockedConsultation->update(['decision' => ConsultationDecision::Surgery]);

            return $surgicalRequest;
        });
    }
}
