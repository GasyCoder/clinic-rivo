<?php

namespace App\Services\Episode;

use App\Models\Episode;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Patient-scoped passage identifier — M-26-0001-01, M-26-0001-02, ...
 *
 * The patient row and its sequence row are locked. The historical episode
 * count is also used as a floor, so imported/legacy episodes whose
 * visit_sequence is NULL can never cause an ordinal to be reused.
 */
class EpisodeNumberGenerator
{
    public function next(Patient $patient): string
    {
        return DB::transaction(function () use ($patient): string {
            $lockedPatient = Patient::query()
                ->lockForUpdate()
                ->findOrFail($patient->getKey());

            $historicalCount = Episode::query()
                ->where('patient_id', $lockedPatient->getKey())
                ->count();
            $highestStoredSequence = (int) (Episode::query()
                ->where('patient_id', $lockedPatient->getKey())
                ->max('visit_sequence') ?? 0);
            $minimumNext = max($historicalCount, $highestStoredSequence) + 1;

            DB::table('episode_number_sequences')->insertOrIgnore([
                'patient_id' => $lockedPatient->getKey(),
                'next_number' => $minimumNext,
            ]);

            $row = DB::table('episode_number_sequences')
                ->where('patient_id', $lockedPatient->getKey())
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw new \RuntimeException('La séquence de passage du patient n’a pas pu être initialisée.');
            }

            $sequence = max((int) $row->next_number, $minimumNext);

            DB::table('episode_number_sequences')
                ->where('id', $row->id)
                ->update(['next_number' => $sequence + 1]);

            return sprintf('%s-%02d', $lockedPatient->patient_number, $sequence);
        });
    }

    /**
     * Extract the ordinal allocated by next() for persistence in
     * episodes.visit_sequence without duplicating the number format in the
     * episode creation action.
     */
    public function sequenceFromNumber(Patient $patient, string $episodeNumber): int
    {
        $prefix = $patient->patient_number.'-';

        if (! str_starts_with($episodeNumber, $prefix)) {
            throw new InvalidArgumentException('Le numéro de passage ne correspond pas au patient.');
        }

        $suffix = substr($episodeNumber, strlen($prefix));

        if ($suffix === '' || ! ctype_digit($suffix) || (int) $suffix < 1) {
            throw new InvalidArgumentException('Le numéro de passage ne contient aucun ordinal valide.');
        }

        return (int) $suffix;
    }
}
