<?php

namespace App\Services\Laboratory;

use App\Models\LabRequest;
use Illuminate\Support\Facades\DB;

/**
 * ADR-214 — le numéro de laboratoire d'une demande, donné à la réception :
 * A-L26-00042. Il sert au registre et aux étiquettes des tubes (A-L26-00042-1).
 *
 * Même règle que le numéro patient : une séquence par année, verrouillée, un
 * numéro déjà porté n'est jamais redonné. Un trou est acceptable, un doublon non.
 */
class LabNumberGenerator
{
    public function next(): string
    {
        return DB::transaction(function (): string {
            $year = now()->year;

            DB::table('lab_number_sequences')->insertOrIgnore(['year' => $year, 'next_number' => 1]);
            $row = DB::table('lab_number_sequences')->where('year', $year)->lockForUpdate()->first();

            $number = (int) $row->next_number;
            $candidate = $this->format($year, $number);
            while (LabRequest::query()->where('lab_number', $candidate)->exists()) {
                $candidate = $this->format($year, ++$number);
            }

            DB::table('lab_number_sequences')->where('id', $row->id)->update(['next_number' => $number + 1]);

            return $candidate;
        });
    }

    public function format(int $year, int $number): string
    {
        $prefix = strtoupper(trim((string) config('rivo.site.code'))) ?: 'X';

        return sprintf('%s-L%02d-%05d', $prefix, $year % 100, $number);
    }
}
