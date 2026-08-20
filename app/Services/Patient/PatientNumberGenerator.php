<?php

namespace App\Services\Patient;

use Illuminate\Support\Facades\DB;

/**
 * Site-prefixed, sequential, human-readable patient identifier — M-000001,
 * A-000001, B-000001, ... (chosen over a plain global sequence so a number
 * stays unambiguous even viewed from the Super Admin portal, which
 * aggregates across sites). Gaps are acceptable (this is a display
 * identifier, not a legal/accounting sequence); uniqueness and safety
 * under concurrent requests are not — hence the row lock.
 */
class PatientNumberGenerator
{
    public function next(): string
    {
        return DB::transaction(function () {
            $row = DB::table('patient_number_sequences')->lockForUpdate()->first();

            if (! $row) {
                $id = DB::table('patient_number_sequences')->insertGetId(['next_number' => 1]);
                $number = 1;
            } else {
                $id = $row->id;
                $number = $row->next_number;
            }

            DB::table('patient_number_sequences')->where('id', $id)->update(['next_number' => $number + 1]);

            $siteCode = config('rivo.site.code') ?: 'X';

            return sprintf('%s-%06d', $siteCode, $number);
        });
    }
}
