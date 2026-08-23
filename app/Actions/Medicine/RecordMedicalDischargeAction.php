<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeAdministrativeStatus;
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

            if (! $latestFinal || trim($latestFinal->description) !== trim($data['final_diagnosis'])) {
                Diagnosis::query()->create([
                    'consultation_id' => $locked->consultation->getKey(),
                    'type' => DiagnosisType::Final,
                    'description' => trim($data['final_diagnosis']),
                    'recorded_by' => $actor->getKey(),
                ]);
            }

            $discharge = MedicalDischarge::query()->create([
                'episode_id' => $locked->episode_id,
                'consultation_id' => $locked->consultation->getKey(),
                'type' => $type,
                'final_diagnosis' => trim($data['final_diagnosis']),
                'patient_condition' => trim($data['patient_condition']),
                'discharge_prescription' => $data['discharge_prescription'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'follow_up_at' => $data['follow_up_at'] ?? null,
                'observations' => $data['observations'] ?? null,
                'transfer_destination' => $data['transfer_destination'] ?? null,
                'death_occurred_at' => $data['death_occurred_at'] ?? null,
                'death_place' => $data['death_place'] ?? null,
                'death_causes' => $data['death_causes'] ?? null,
                'discharged_at' => $data['discharged_at'] ?? now(),
                'created_by' => $actor->getKey(),
            ]);

            $locked->consultation->update([
                'decision' => $type === MedicalDischargeType::Transfer
                    ? ConsultationDecision::ExternalTransfer
                    : ConsultationDecision::Discharge,
            ]);

            $locked->complete($actor);
            $locked->episode->medical_status = $type->medicalStatus();

            if ($locked->episode->administrative_status === EpisodeAdministrativeStatus::InCare) {
                $locked->episode->administrative_status = EpisodeAdministrativeStatus::PendingSettlement;
            }

            $locked->episode->save();

            return $discharge->fresh(['creator:id,name']);
        });
    }
}
