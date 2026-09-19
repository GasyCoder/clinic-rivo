<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalDischargeType;
use App\Models\HospitalStay;
use App\Models\MedicalDischarge;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 — le médecin termine le séjour par la sortie médicale (CDC §33.1 :
 * le statut médical relève d'une décision médicale).
 *
 * C'est la même `MedicalDischarge` que celle d'une consultation (ADR-035) :
 * un passage n'en porte qu'une, avec les mêmes types, les mêmes champs, et
 * le même effet sur le statut médical. Elle est rattachée à la consultation
 * qui a demandé l'hospitalisation — sans la rouvrir ni la modifier : aucun
 * diagnostic n'y est ajouté, le diagnostic final vit sur la sortie.
 *
 * Un décès prononcé ici rejoint le registre des décès (ADR-107) comme tout
 * autre. Le passage rejoint ensuite « Sorties & règlements » selon la règle
 * de l'ADR-054 : la sortie administrative reste à la Réception.
 */
class DischargeHospitalStayAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<string, mixed> $data */
    public function execute(HospitalStay $stay, array $data, User $actor): MedicalDischarge
    {
        return DB::transaction(function () use ($stay, $data, $actor): MedicalDischarge {
            $locked = HospitalStay::query()
                ->with(['episode.medicalDischarge', 'hospitalizationRequest', 'episodeOrientation'])
                ->lockForUpdate()
                ->findOrFail($stay->getKey());

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Ce séjour est déjà terminé.',
                ]);
            }

            $episode = $locked->episode;

            if ($episode->medicalDischarge) {
                throw ValidationException::withMessages([
                    'medical_discharge' => 'Une sortie médicale a déjà été prononcée pour ce passage.',
                ]);
            }

            $type = MedicalDischargeType::from($data['type']);
            $dischargedAt = $data['discharged_at'] ?? now();

            $discharge = MedicalDischarge::query()->create([
                'episode_id' => $episode->getKey(),
                'consultation_id' => $locked->hospitalizationRequest->consultation_id,
                'type' => $type,
                'final_diagnosis' => trim((string) $data['final_diagnosis']),
                // Pour un décès, le type de sortie *est* l'état (ADR-107).
                'patient_condition' => $type === MedicalDischargeType::Deceased
                    ? EpisodeMedicalStatus::Deceased->label()
                    : trim((string) ($data['patient_condition'] ?? '')),
                'discharge_prescription' => $type === MedicalDischargeType::Deceased ? null : ($data['discharge_prescription'] ?? null),
                'recommendations' => $type === MedicalDischargeType::Deceased ? null : ($data['recommendations'] ?? null),
                'follow_up_at' => $type === MedicalDischargeType::Deceased ? null : ($data['follow_up_at'] ?? null),
                'observations' => $data['observations'] ?? null,
                'transfer_destination' => $data['transfer_destination'] ?? null,
                'death_occurred_at' => $data['death_occurred_at'] ?? null,
                'death_place' => $data['death_place'] ?? null,
                'death_causes' => $data['death_causes'] ?? null,
                'discharged_at' => $dischargedAt,
                'created_by' => $actor->getKey(),
            ]);

            $locked->update([
                'status' => HospitalStayStatus::Discharged,
                'discharged_at' => $dischargedAt,
                'discharged_by' => $actor->getKey(),
                'medical_discharge_id' => $discharge->getKey(),
                'active_key' => null,
            ]);

            $orientation = $locked->episodeOrientation;

            if ($orientation && $orientation->status === EpisodeOrientationStatus::InProgress) {
                $orientation->complete($actor);
            }

            $episode->medical_status = $type->medicalStatus();

            // Même règle que la fin des Soins et la clôture de consultation
            // (ADR-054) : la suite devient administrative seulement quand plus
            // aucun service n'a le patient.
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
                'hospitalization.discharge',
                $locked,
                ['status' => HospitalStayStatus::Discharged->value, 'discharge_type' => $type->value],
                ['status' => HospitalStayStatus::Active->value],
                null,
                'hospitalization',
                $actor,
            );

            return $discharge;
        });
    }
}
