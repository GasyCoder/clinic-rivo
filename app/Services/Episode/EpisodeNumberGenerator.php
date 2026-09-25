<?php

namespace App\Services\Episode;

use App\Models\Episode;
use App\Models\Patient;
use App\Services\Settings\AppSettings;
use App\Support\Numbering\PatientNumberFormat;
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
    /**
     * ADR-191 — séparateur et nombre de chiffres du rang réglés pour le site
     * (par défaut « -01 »). Un numéro qui existerait déjà est sauté.
     */
    public function __construct(private readonly ?AppSettings $settings = null) {}

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

            $format = ($this->settings ?? app(AppSettings::class))->patientNumbering();
            $candidate = $format->episode($lockedPatient->patient_number, $sequence);

            while (Episode::query()->where('episode_number', $candidate)->exists()) {
                $candidate = $format->episode($lockedPatient->patient_number, ++$sequence);
            }

            DB::table('episode_number_sequences')
                ->where('id', $row->id)
                ->update(['next_number' => $sequence + 1]);

            return $candidate;
        });
    }

    /**
     * Extract the ordinal allocated by next() for persistence in
     * episodes.visit_sequence without duplicating the number format in the
     * episode creation action.
     */
    public function sequenceFromNumber(Patient $patient, string $episodeNumber): int
    {
        // Le séparateur est réglé par site (ADR-191) : tout séparateur permis est accepté.
        $prefix = $patient->patient_number;
        $separator = substr($episodeNumber, strlen($prefix), 1);

        if (! str_starts_with($episodeNumber, $prefix) || ! in_array($separator, PatientNumberFormat::SEPARATORS, true)) {
            throw new InvalidArgumentException('Le numéro de passage ne correspond pas au patient.');
        }

        $suffix = substr($episodeNumber, strlen($prefix) + 1);

        if ($suffix === '' || ! ctype_digit($suffix) || (int) $suffix < 1) {
            throw new InvalidArgumentException('Le numéro de passage ne contient aucun ordinal valide.');
        }

        return (int) $suffix;
    }
}
