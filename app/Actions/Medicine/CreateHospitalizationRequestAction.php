<?php

namespace App\Actions\Medicine;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Actions\Hospitalization\AdmitHospitalStayAction;
use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalRequestStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\HospitalizationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The doctor asks for the patient to be admitted.
 *
 * Creates the DEMANDE and the orientation that carries it to the ward —
 * and, since ADR-113, the stay itself: admission is automatic at request
 * time (owner's decision), so the patient is hospitalised from this moment.
 * The stay ends with the doctor's medical discharge.
 *
 * @see CreateSurgicalReferralAction for the same shape toward Chirurgie.
 */
class CreateHospitalizationRequestAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly RecordConsultationOrientationAction $recordOrientation,
        private readonly AdmitHospitalStayAction $admit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Consultation $consultation, array $data, User $actor): HospitalizationRequest
    {
        return DB::transaction(function () use ($consultation, $data, $actor): HospitalizationRequest {
            $lockedConsultation = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());
            $this->recordOrientation->ensureNotAlreadySubmitted($lockedConsultation, ConsultationOrientationType::Hospitalization);
            $medicineOrientation = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($lockedConsultation->episode_orientation_id);

            if ($medicineOrientation->destination_module !== CatalogModule::Medicine
                || $medicineOrientation->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'hospitalization' => 'Cette consultation n’est plus active.',
                ]);
            }

            $priority = ClinicalPriority::from($data['priority']);
            // ADR-113 — le motif est repris du dossier, puis complété dans le
            // module Hospitalisation : il peut partir vide, jamais inventé.
            $reason = trim((string) ($data['reason'] ?? '')) ?: null;
            $orientation = $this->createOrientation->execute(
                $medicineOrientation->episode,
                CatalogModule::Medicine,
                CatalogModule::Hospitalization,
                $actor,
                $reason,
            );

            $request = HospitalizationRequest::query()->create([
                'episode_id' => $medicineOrientation->episode_id,
                'consultation_id' => $lockedConsultation->getKey(),
                'episode_orientation_id' => $orientation->getKey(),
                'reason' => $reason,
                'admission_diagnosis' => $data['admission_diagnosis'] ?? null,
                'clinical_summary' => $data['clinical_summary'] ?? null,
                'planned_treatment' => $data['planned_treatment'] ?? null,
                'requested_service' => $data['requested_service'] ?? null,
                'requested_admission_at' => $data['requested_admission_at'] ?? null,
                'priority' => $priority,
                'instructions' => $data['instructions'] ?? null,
                'status' => MedicalRequestStatus::Requested,
                'requested_by' => $actor->getKey(),
                'requested_at' => now(),
            ]);

            // ADR-113 — l'admission est automatique : le séjour commence ici.
            $this->admit->execute($request, $orientation, $actor);

            $this->recordOrientation->submit(
                $lockedConsultation,
                ConsultationOrientationType::Hospitalization,
                [
                    'episode_orientation_id' => $orientation->getKey(),
                    'hospitalization_request_id' => $request->getKey(),
                ],
                $priority,
                $actor,
            );

            return $request->fresh(['requestedBy:id,name']);
        });
    }
}
