<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\MaternityEncounterType;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-204 — la sage-femme dit ce qu'elle commence : une consultation
 * prénatale ou un accouchement.
 *
 * Un geste explicite, jamais un effet de regard (ADR-177) : c'est lui qui
 * ouvre le dossier Maternité du passage, avec son parcours. La grossesse, elle,
 * reste choisie à part — continuer ou créer (ADR-201) — et rien n'est rattaché
 * en silence.
 *
 * Changer de parcours reste possible tant que le dossier n'est pas terminé
 * (une consultation où le travail commence devient un accouchement) : les
 * données déjà saisies ne se perdent pas, elles vivent dans le même dossier.
 * Le changement est tracé par l'audit du dossier (`Auditable`).
 */
final class StartMaternityEncounterAction
{
    public function execute(EpisodeOrientation $orientation, MaternityEncounterType $type, User $actor): MaternityRecord
    {
        return DB::transaction(function () use ($orientation, $type, $actor): MaternityRecord {
            $locked = EpisodeOrientation::query()->with('episode')->lockForUpdate()->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Maternity) {
                abort(404);
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress || $locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'encounter_type' => 'Prenez d’abord la patiente en charge : le parcours se choisit pendant une prise en charge Maternité en cours.',
                ]);
            }

            $record = MaternityRecord::query()
                ->where('episode_id', $locked->episode_id)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return MaternityRecord::query()->create([
                    'episode_id' => $locked->episode_id,
                    'episode_orientation_id' => $locked->getKey(),
                    'encounter_type' => $type,
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);
            }

            if ($record->isFinalized()) {
                throw ValidationException::withMessages([
                    'encounter_type' => 'Ce dossier Maternité est terminé : son parcours ne change plus.',
                ]);
            }

            if ($record->encounter_type !== $type) {
                $record->fill(['encounter_type' => $type, 'updated_by' => $actor->getKey()])->save();
            }

            return $record->fresh();
        });
    }
}
