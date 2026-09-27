<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeStatus;
use App\Models\HospitalDietEntry;
use App\Models\HospitalStay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 — une ligne de la fiche de régime, ajoutée ou corrigée.
 *
 * Ajouter exige un séjour en cours. Corriger reste possible après la sortie
 * tant que le passage est ouvert, comme la fiche Soins (ADR-092) ; une ligne
 * n'est jamais supprimée.
 */
class RecordHospitalDietEntryAction
{
    /** @param array<string, mixed> $data */
    public function execute(HospitalStay $stay, array $data, User $actor, ?HospitalDietEntry $entry = null): HospitalDietEntry
    {
        return DB::transaction(function () use ($stay, $data, $actor, $entry): HospitalDietEntry {
            $locked = HospitalStay::query()->with('episode')->lockForUpdate()->findOrFail($stay->getKey());

            if ($entry === null && ! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'diet' => 'Le séjour est terminé : aucune nouvelle ligne ne peut être ajoutée.',
                ]);
            }

            if ($locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages([
                    'diet' => 'Le passage est clos : la fiche de régime n’est plus modifiable.',
                ]);
            }

            if ($entry !== null && $entry->hospital_stay_id !== $locked->getKey()) {
                abort(404);
            }

            $values = [
                'served_on' => $data['served_on'],
                'served_time' => $data['served_time'],
                'observation' => self::clean($data['observation'] ?? null),
            ];

            foreach (HospitalDietEntry::MEAL_FIELDS as $field) {
                $values[$field] = self::clean($data[$field] ?? null);
            }

            if ($entry === null) {
                return HospitalDietEntry::query()->create($values + [
                    'hospital_stay_id' => $locked->getKey(),
                    'recorded_by' => $actor->getKey(),
                ]);
            }

            $entry->update($values + ['updated_by' => $actor->getKey()]);

            return $entry->fresh();
        });
    }

    private static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
