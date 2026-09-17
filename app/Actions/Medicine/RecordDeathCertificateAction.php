<?php

namespace App\Actions\Medicine;

use App\Enums\MedicalDischargeType;
use App\Models\DeathRecord;
use App\Models\Episode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Établir l'acte de constatation d'un décès (ADR-107).
 *
 * L'acte ne prononce pas le décès : c'est la sortie médicale qui l'a fait
 * (ADR-035). Il ne peut donc exister que pour un passage dont la sortie
 * porte le type `DECEASED`, et ne peut pas le devancer — signer la
 * constatation d'un décès que personne n'a prononcé attesterait un fait
 * clinique inexistant.
 */
class RecordDeathCertificateAction
{
    /** @param array<string, mixed> $data */
    public function execute(Episode $episode, array $data, User $actor): DeathRecord
    {
        return DB::transaction(function () use ($episode, $data, $actor): DeathRecord {
            /** @var Episode $locked */
            $locked = Episode::query()
                ->with(['medicalDischarge', 'deathRecord'])
                ->lockForUpdate()
                ->findOrFail($episode->getKey());

            $discharge = $locked->medicalDischarge;

            if (! $discharge || $discharge->type !== MedicalDischargeType::Deceased) {
                throw ValidationException::withMessages([
                    'death_record' => 'Ce passage ne porte aucune sortie médicale de type Décès.',
                ]);
            }

            // Un second acte serait un doublon d'état civil, jamais une
            // correction : celle-ci relève d'un mécanisme tracé (ADR-010).
            if ($locked->deathRecord) {
                throw ValidationException::withMessages([
                    'death_record' => 'L’acte de constatation de ce passage est déjà établi.',
                ]);
            }

            return DeathRecord::query()->create([
                'episode_id' => $locked->getKey(),
                'patient_id' => $locked->patient_id,
                'medical_discharge_id' => $discharge->getKey(),
                // Préremplis depuis la sortie médicale, corrigeables ici :
                // le médecin qui constate signe ce qu'il écrit, il ne
                // contresigne pas la saisie d'un autre écran.
                'death_occurred_at' => $data['death_occurred_at'],
                'death_place' => $data['death_place'],
                'death_causes' => $data['death_causes'],
                'observations' => $data['observations'] ?? null,
                // L'heure de la constatation appartient au serveur : elle
                // atteste quand l'acte a été signé, pas quand on a rempli le
                // formulaire (même règle que la date de demande, ADR-069).
                'constated_at' => now(),
                'constated_by' => $actor->getKey(),
            ]);
        });
    }
}
