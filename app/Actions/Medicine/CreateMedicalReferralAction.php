<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalRequestStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\MedicalReferral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The doctor refers the patient out — another establishment, or another
 * site of the clinic.
 *
 * Records what the receiving team has to read and nothing about what they
 * then do: there is no departure, no arrival and no acknowledgement here.
 * Whether the patient actually left is a fact the sending doctor cannot
 * observe, and a status claiming it would be fabricated.
 *
 * A transfer is not a medical discharge. `MedicalDischarge` of type
 * TRANSFER remains the act that ends the medical pathway (ADR-035); this
 * request is what the destination receives.
 */
class CreateMedicalReferralAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly RecordConsultationOrientationAction $recordOrientation,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Consultation $consultation, array $data, User $actor): MedicalReferral
    {
        return DB::transaction(function () use ($consultation, $data, $actor): MedicalReferral {
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

            $priority = ClinicalPriority::from($data['priority']);
            $orientation = $this->createOrientation->execute(
                $medicineOrientation->episode,
                CatalogModule::Medicine,
                CatalogModule::Transfer,
                $actor,
                trim($data['facility']).' — '.trim($data['reason']),
            );

            $referral = MedicalReferral::query()->create([
                'episode_id' => $medicineOrientation->episode_id,
                'consultation_id' => $lockedConsultation->getKey(),
                'episode_orientation_id' => $orientation->getKey(),
                'facility' => trim($data['facility']),
                'reason' => trim($data['reason']),
                'diagnosis' => $data['diagnosis'] ?? null,
                'clinical_summary' => $data['clinical_summary'] ?? null,
                'treatments_given' => $data['treatments_given'] ?? null,
                'priority' => $priority,
                'recommendations' => $data['recommendations'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => MedicalRequestStatus::Requested,
                'referred_by' => $actor->getKey(),
                'referred_at' => now(),
            ]);

            $this->recordOrientation->submit(
                $lockedConsultation,
                ConsultationOrientationType::Referral,
                [
                    'episode_orientation_id' => $orientation->getKey(),
                    'medical_referral_id' => $referral->getKey(),
                ],
                $priority,
                $actor,
            );

            return $referral->fresh(['referredBy:id,name']);
        });
    }
}
