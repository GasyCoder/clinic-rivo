<?php

namespace App\Services\Patient;

use App\Models\Patient;
use App\Services\Settings\AppSettings;
use App\Support\Numbering\PatientNumberFormat;
use Illuminate\Support\Facades\DB;

/**
 * Site/year-prefixed patient identifier — M-26-0001, A-26-0001, ...
 *
 * Each operational site still has its own database, while the full year is
 * stored on the sequence row so a new calendar year safely restarts at 1.
 * Existing identifiers are immutable. Gaps are acceptable; duplicates under
 * concurrent requests are not, hence the unique year and row lock.
 *
 * ADR-191 — the shape (prefix, year, digits, separator, yearly or continuous
 * counter) is set per site; unset, it is exactly the one above. A continuous
 * counter uses the sequence row keyed 0. Changing the shape never rewrites a
 * number already given, and a candidate that already exists — an older shape
 * can produce the same string — is skipped, never reused.
 */
class PatientNumberGenerator
{
    /** Au-delà, la forme réglée ne produit plus que des numéros déjà pris : mieux vaut le dire. */
    private const MAX_SKIPS = 10000;

    public function __construct(private readonly ?AppSettings $settings = null) {}

    private function format(): PatientNumberFormat
    {
        return ($this->settings ?? app(AppSettings::class))->patientNumbering();
    }

    public function next(): string
    {
        $format = $this->format();

        return DB::transaction(function () use ($format) {
            $year = now()->year;
            $key = $format->sequenceKey($year);

            // Atomic even when two requests allocate the first number of a
            // year simultaneously: the unique year lets only one row win.
            DB::table('patient_number_sequences')->insertOrIgnore([
                'year' => $key,
                'next_number' => 1,
            ]);

            $row = DB::table('patient_number_sequences')
                ->where('year', $key)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw new \RuntimeException("La séquence patient {$key} n’a pas pu être initialisée.");
            }

            $number = (int) $row->next_number;
            $candidate = $format->patient($year, $number);
            $skips = 0;

            while (Patient::withTrashed()->where('patient_number', $candidate)->exists()) {
                if (++$skips > self::MAX_SKIPS) {
                    throw new \RuntimeException('La numérotation des patients ne produit plus que des numéros déjà attribués : revoyez son format.');
                }

                $candidate = $format->patient($year, ++$number);
            }

            DB::table('patient_number_sequences')
                ->where('id', $row->id)
                ->update(['next_number' => $number + 1]);

            return $candidate;
        });
    }

    /**
     * ADR-191 — le prochain numéro, sans le consommer : ce que l'écran des
     * paramètres montre. Rien n'est réservé ; le vrai numéro est attribué à la
     * création du patient.
     */
    public function peek(): string
    {
        $format = $this->format();
        $year = now()->year;
        $number = (int) (DB::table('patient_number_sequences')->where('year', $format->sequenceKey($year))->value('next_number') ?? 1);
        $candidate = $format->patient($year, $number);

        for ($skips = 0; $skips < self::MAX_SKIPS && Patient::withTrashed()->where('patient_number', $candidate)->exists(); $skips++) {
            $candidate = $format->patient($year, ++$number);
        }

        return $candidate;
    }

    /**
     * Numéro d'un nouveau-né, dérivé de celui de sa mère — A-26-0009-B1, A-26-0009-B2… (ADR-144).
     *
     * Le préfixe `B` le distingue d'un passage (`A-26-0009-01`) ; le rang est celui de la naissance,
     * pour que des jumeaux se lisent dans leur ordre. S'il est déjà pris — le second jumeau a eu
     * son dossier en premier —, le plus petit rang libre est choisi : un numéro n'est jamais réutilisé,
     * y compris celui d'un dossier archivé, l'index unique les compte tous.
     *
     * La mère est verrouillée : deux créations simultanées pour la même mère se suivent.
     */
    public function newborn(Patient $mother, int $rank): string
    {
        return DB::transaction(function () use ($mother, $rank): string {
            $lockedMother = Patient::query()->lockForUpdate()->findOrFail($mother->getKey());
            $suffix = max(1, $rank);

            $format = $this->format();

            while (Patient::withTrashed()
                ->where('patient_number', $format->newborn($lockedMother->patient_number, $suffix))
                ->exists()) {
                $suffix++;
            }

            return $format->newborn($lockedMother->patient_number, $suffix);
        });
    }
}
