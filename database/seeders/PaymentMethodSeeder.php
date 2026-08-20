<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'CASH', 'name' => 'Espèces', 'affects_cash_balance' => true],
            ['code' => 'MOBILE_MONEY', 'name' => 'Mobile money', 'affects_cash_balance' => false],
            ['code' => 'BANK_TRANSFER', 'name' => 'Virement', 'affects_cash_balance' => false],
            ['code' => 'PARTNER_COVERAGE', 'name' => 'Prise en charge partenaire', 'affects_cash_balance' => false],
            ['code' => 'OTHER', 'name' => 'Autre', 'affects_cash_balance' => false],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => $method['code']],
                [...$method, 'active' => true],
            );
        }
    }
}
