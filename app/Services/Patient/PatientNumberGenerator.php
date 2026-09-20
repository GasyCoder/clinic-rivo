<?php

namespace App\Services\Patient;

use App\Models\Patient;
use Illuminate\Support\Facades\DB;

/**
 * Site/year-prefixed patient identifier — M-26-0001, A-26-0001, ...
 *
 * Each operational site still has its own database, while the full year is
 * stored on the sequence row so a new calendar year safely restarts at 1.
 * Existing identifiers are immutable. Gaps are acceptable; duplicates under
 * concurrent requests are not, hence the unique year and row lock.
 */
class PatientNumberGenerator
{
    public function next(): string
    {
        return DB::transaction(function () {
            $year = now()->year;

            // Atomic even when two requests allocate the first number of a
            // year simultaneously: the unique year lets only one row win.
            DB::table('patient_number_sequences')->insertOrIgnore([
                'year' => $year,
                'next_number' => 1,
            ]);

            $row = DB::table('patient_number_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw new \RuntimeException("La séquence patient {$year} n’a pas pu être initialisée.");
            }

            $number = (int) $row->next_number;

            DB::table('patient_number_sequences')
                ->where('id', $row->id)
                ->update(['next_number' => $number + 1]);

            $siteCode = strtoupper(trim((string) config('rivo.site.code'))) ?: 'X';

            return sprintf('%s-%02d-%04d', $siteCode, $year % 100, $number);
        });
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

            while (Patient::withTrashed()
                ->where('patient_number', "{$lockedMother->patient_number}-B{$suffix}")
                ->exists()) {
                $suffix++;
            }

            return "{$lockedMother->patient_number}-B{$suffix}";
        });
    }
}
