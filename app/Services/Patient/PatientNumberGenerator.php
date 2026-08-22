<?php

namespace App\Services\Patient;

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
}
