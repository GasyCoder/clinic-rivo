<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;

class FinancialNumberGenerator
{
    public function invoice(): string
    {
        return $this->next('invoice', 'I');
    }

    public function payment(): string
    {
        return $this->next('payment', 'P');
    }

    public function receipt(): string
    {
        return $this->next('receipt', 'R');
    }

    public function cashSession(): string
    {
        return $this->next('cash_session', 'C');
    }

    private function next(string $code, string $marker): string
    {
        return DB::transaction(function () use ($code, $marker) {
            // Seed the row atomically, then lock it. insertOrIgnore avoids
            // two first-use requests allocating the same number.
            DB::table('financial_number_sequences')->insertOrIgnore([
                'code' => $code,
                'next_number' => 1,
            ]);

            $row = DB::table('financial_number_sequences')
                ->where('code', $code)
                ->lockForUpdate()
                ->first();
            $number = $row->next_number;
            DB::table('financial_number_sequences')
                ->where('code', $code)
                ->update(['next_number' => $number + 1]);

            $siteCode = config('rivo.site.code') ?: 'X';

            return sprintf('%s%s-%06d', $siteCode, $marker, $number);
        });
    }
}
