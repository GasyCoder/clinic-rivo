<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orienting the patient toward an internal service that has no request
 * model of its own: Maternité and Pédiatrie.
 *
 * Only `EpisodeOrientation` is reused, its `reason` carrying the structured
 * text the doctor entered. Maternité already consumes that orientation in
 * its own workspace; Pédiatrie has none yet, and inventing a table for a
 * module whose workflow is undefined would fabricate process (§10).
 *
 * Hospitalisation and Référence/Transfert used to pass through here too.
 * They now have their own request records, because what the receiving team
 * needs to read — ward, requested admission date, destination facility,
 * treatments already given — cannot be held as one line of free text.
 */
class CreateServiceReferralAction
{
    private const TYPE_BY_DESTINATION = [
        'MATERNITY' => ConsultationOrientationType::Maternity,
        'PEDIATRICS' => ConsultationOrientationType::Pediatrics,
    ];

    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly RecordConsultationOrientationAction $recordOrientation,
    ) {}

    public function execute(
        Consultation $consultation,
        CatalogModule $destination,
        string $reason,
        User $actor,
        ?ClinicalPriority $priority = null,
    ): EpisodeOrientation {
        return DB::transaction(function () use ($consultation, $destination, $reason, $actor, $priority): EpisodeOrientation {
            $lockedConsultation = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $medicineOrientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($lockedConsultation->episode_orientation_id);

            if ($medicineOrientation->destination_module !== CatalogModule::Medicine
                || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'referral' => 'Cette consultation n’est plus active.',
                ]);
            }

            $type = self::TYPE_BY_DESTINATION[$destination->value] ?? null;

            if ($type === null) {
                throw ValidationException::withMessages([
                    'destination' => 'Cette destination possède son propre formulaire de demande.',
                ]);
            }

            $orientation = $this->createOrientation->execute(
                $medicineOrientation->episode,
                CatalogModule::Medicine,
                $destination,
                $actor,
                $reason,
            );

            $this->recordOrientation->submit(
                $lockedConsultation,
                $type,
                ['episode_orientation_id' => $orientation->getKey()],
                $priority,
                $actor,
            );

            return $orientation;
        });
    }
}
