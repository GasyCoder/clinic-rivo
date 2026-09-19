<?php

namespace App\Actions\Pediatrics;

use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
use App\Models\ConsultationOrientation;
use App\Models\EpisodeOrientation;
use App\Models\MedicalDischarge;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-114 — la Pédiatrie termine sa prise en charge par la sortie médicale.
 *
 * Aucune fiche pédiatrique n'est inventée tant que la clinique n'en a pas
 * fourni une : la prise en charge se conclut par la même `MedicalDischarge`
 * qu'une consultation ou qu'un séjour (ADR-035, ADR-113), rattachée à la
 * consultation qui a orienté le patient — sans la rouvrir ni la modifier.
 */
class DischargePediatricsOrientationAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<string, mixed> $data */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): MedicalDischarge
    {
        return DB::transaction(function () use ($orientation, $data, $actor): MedicalDischarge {
            $locked = EpisodeOrientation::query()
                ->with('episode.medicalDischarge')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Pediatrics
                || $locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Prenez d’abord le patient en charge en Pédiatrie.',
                ]);
            }

            $episode = $locked->episode;

            if ($episode->medicalDischarge) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Une sortie médicale a déjà été prononcée pour ce passage.',
                ]);
            }

            $consultationId = ConsultationOrientation::query()
                ->where('episode_orientation_id', $locked->getKey())
                ->value('consultation_id');

            $type = MedicalDischargeType::from($data['type']);
            $deceased = $type === MedicalDischargeType::Deceased;
            $dischargedAt = $data['discharged_at'] ?? now();

            $discharge = MedicalDischarge::query()->create([
                'episode_id' => $episode->getKey(),
                'consultation_id' => $consultationId,
                'type' => $type,
                'final_diagnosis' => trim((string) ($data['final_diagnosis'] ?? '')) ?: null,
                'patient_condition' => $deceased
                    ? EpisodeMedicalStatus::Deceased->label()
                    : trim((string) ($data['patient_condition'] ?? '')),
                'discharge_prescription' => $deceased ? null : ($data['discharge_prescription'] ?? null),
                'recommendations' => $deceased ? null : ($data['recommendations'] ?? null),
                'follow_up_at' => $deceased ? null : ($data['follow_up_at'] ?? null),
                'observations' => $data['observations'] ?? null,
                'transfer_destination' => $data['transfer_destination'] ?? null,
                'death_occurred_at' => $data['death_occurred_at'] ?? null,
                'death_place' => $data['death_place'] ?? null,
                'death_causes' => $data['death_causes'] ?? null,
                'discharged_at' => $dischargedAt,
                'created_by' => $actor->getKey(),
            ]);

            $locked->complete($actor);

            $episode->medical_status = $type->medicalStatus();

            if ($episode->administrative_status === EpisodeAdministrativeStatus::InCare
                && ! $episode->orientations()
                    ->whereIn('status', [
                        EpisodeOrientationStatus::Pending->value,
                        EpisodeOrientationStatus::InProgress->value,
                    ])
                    ->exists()) {
                $episode->administrative_status = EpisodeAdministrativeStatus::PendingSettlement;
            }

            $episode->save();

            $this->auditor->record(
                'pediatrics.discharge',
                $locked,
                ['status' => EpisodeOrientationStatus::Completed->value, 'discharge_type' => $type->value],
                ['status' => EpisodeOrientationStatus::InProgress->value],
                null,
                'pediatrics',
                $actor,
            );

            return $discharge;
        });
    }
}
