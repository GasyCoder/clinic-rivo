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
 * Records what the receiving team has to read. The departure is not the
 * doctor's to claim: it is recorded later, in the Transferts workspace,
 * by whoever sees the patient leave (« Transfert effectué », ADR-114).
 * There is still no arrival and no acknowledgement — nobody here can
 * observe them.
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
            $this->recordOrientation->ensureNotAlreadySubmitted($lockedConsultation, ConsultationOrientationType::Referral);
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
            // ADR-114 — la demande part en un clic : l'établissement et le
            // motif se complètent dans le module Transferts, jamais inventés.
            $facility = trim((string) ($data['facility'] ?? '')) ?: null;
            $reason = trim((string) ($data['reason'] ?? '')) ?: null;
            $orientation = $this->createOrientation->execute(
                $medicineOrientation->episode,
                CatalogModule::Medicine,
                CatalogModule::Transfer,
                $actor,
                implode(' — ', array_filter([$facility, $reason])) ?: null,
            );

            $referral = MedicalReferral::query()->create([
                'episode_id' => $medicineOrientation->episode_id,
                'consultation_id' => $lockedConsultation->getKey(),
                'episode_orientation_id' => $orientation->getKey(),
                'facility' => $facility,
                'reason' => $reason,
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
