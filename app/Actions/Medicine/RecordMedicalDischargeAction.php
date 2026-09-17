<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ConsultationOrientationType;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Models\MedicalDischarge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordMedicalDischargeAction
{
    public function __construct(
        private readonly RecordConsultationOrientationAction $recordOrientation,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): MedicalDischarge
    {
        return DB::transaction(function () use ($orientation, $data, $actor): MedicalDischarge {
            $locked = EpisodeOrientation::query()
                ->with(['episode.medicalDischarge', 'consultation'])
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Medicine
                || $locked->status !== EpisodeOrientationStatus::InProgress
                || ! $locked->consultation) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'La sortie exige une consultation Médecine active.',
                ]);
            }

            if ($locked->episode->medicalDischarge) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Une sortie médicale a déjà été prononcée pour ce passage.',
                ]);
            }

            $type = MedicalDischargeType::from($data['type']);
            $latestFinal = $locked->consultation->diagnoses()
                ->where('type', DiagnosisType::Final->value)
                ->whereDoesntHave('cancellation')
                ->latest('id')
                ->first();

            // ADR-094 — absent pour un passage paraclinique seul, où le
            // résultat de l'examen tient lieu de conclusion. On ne fabrique
            // alors ni diagnostic vide, ni chaîne vide : une absence reste
            // une absence.
            $finalDiagnosis = trim((string) ($data['final_diagnosis'] ?? ''));

            if ($finalDiagnosis !== ''
                && (! $latestFinal || trim($latestFinal->description) !== $finalDiagnosis)) {
                Diagnosis::query()->create([
                    'consultation_id' => $locked->consultation->getKey(),
                    'type' => DiagnosisType::Final,
                    'description' => $finalDiagnosis,
                    'recorded_by' => $actor->getKey(),
                ]);
            }

            $discharge = MedicalDischarge::query()->create([
                'episode_id' => $locked->episode_id,
                'consultation_id' => $locked->consultation->getKey(),
                'type' => $type,
                'final_diagnosis' => $finalDiagnosis !== '' ? $finalDiagnosis : null,
                // ADR-107 — pour un décès, l'état du patient n'est pas un
                // choix : le type de sortie *est* la réponse. Le dériver
                // ici n'invente rien, c'est la même information que porte
                // déjà `MedicalDischargeType::episodeMedicalStatus()`.
                'patient_condition' => $type === MedicalDischargeType::Deceased
                    // « Décédé » décrit le patient ; « Décès » décrit le type
                    // de sortie. C'est bien l'état du patient qu'on écrit
                    // ici, et c'est le statut que le passage prendra.
                    ? EpisodeMedicalStatus::Deceased->label()
                    : trim((string) ($data['patient_condition'] ?? '')),
                // Un traitement de sortie, des conseils de surveillance et
                // un rendez-vous n'ont pas de destinataire. La FormRequest
                // les refuse déjà ; l'Action est atteignable autrement
                // qu'elle, et ne doit pas pouvoir les écrire non plus.
                'discharge_prescription' => $type === MedicalDischargeType::Deceased ? null : ($data['discharge_prescription'] ?? null),
                'recommendations' => $type === MedicalDischargeType::Deceased ? null : ($data['recommendations'] ?? null),
                'follow_up_at' => $type === MedicalDischargeType::Deceased ? null : ($data['follow_up_at'] ?? null),
                'observations' => $data['observations'] ?? null,
                'transfer_destination' => $data['transfer_destination'] ?? null,
                'death_occurred_at' => $data['death_occurred_at'] ?? null,
                'death_place' => $data['death_place'] ?? null,
                'death_causes' => $data['death_causes'] ?? null,
                'discharged_at' => $data['discharged_at'] ?? now(),
                'created_by' => $actor->getKey(),
            ]);

            // Recording the discharge no longer ends the encounter (ADR-084).
            // The Médecine orientation and the episode statuses move at the
            // Clôture step, so a doctor who pronounces a discharge can still
            // prescribe, print and check their file before closing — which
            // is exactly what the old "Décision"-last pathway prevented.
            $this->recordOrientation->submit(
                $locked->consultation,
                $type === MedicalDischargeType::Transfer
                    ? ConsultationOrientationType::Referral
                    : ConsultationOrientationType::Discharge,
                ['medical_discharge_id' => $discharge->getKey()],
                null,
                $actor,
            );

            return $discharge->fresh(['creator:id,name']);
        });
    }
}
