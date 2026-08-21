<?php

namespace App\Services\Episode;

use Illuminate\Support\Facades\DB;

/**
 * Site-prefixed, sequential episode (passage) identifier — MP-000001,
 * AP-000001, ... Same shape and safety properties as PatientNumberGenerator
 * (row-locked counter, gaps acceptable, uniqueness under concurrency is
 * not) but with a 'P' marker (Passage) so an episode number is never
 * visually confused with a patient number when the two are shown side by
 * side.
 */
class EpisodeNumberGenerator
{
    public function next(): string
    {
        return DB::transaction(function () {
            $row = DB::table('episode_number_sequences')->lockForUpdate()->first();

            if (! $row) {
                $id = DB::table('episode_number_sequences')->insertGetId(['next_number' => 1]);
                $number = 1;
            } else {
                $id = $row->id;
                $number = $row->next_number;
            }

            DB::table('episode_number_sequences')->where('id', $id)->update(['next_number' => $number + 1]);

            $siteCode = config('rivo.site.code') ?: 'X';

            return sprintf('%sP-%06d', $siteCode, $number);
        });
    }
}
