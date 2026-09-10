<?php

namespace Database\Seeders;

use App\Enums\PaymentMethodCategory;
use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $cash = PaymentMethodCategory::Cash->value;
        $mobile = PaymentMethodCategory::MobileMoney->value;
        $bank = PaymentMethodCategory::Bank->value;

        $methods = [
            // Cash carries no external reference: its generated payment
            // number is the only one. Every other tender does.
            ['code' => 'CASH', 'name' => 'Espèces', 'category' => $cash, 'affects_cash_balance' => true, 'requires_reference' => false],
            ['code' => 'MOBILE_MONEY_ORANGE', 'name' => 'Orange Money', 'category' => $mobile, 'affects_cash_balance' => false, 'requires_reference' => true],
            ['code' => 'MOBILE_MONEY_MVOLA', 'name' => 'MVola', 'category' => $mobile, 'affects_cash_balance' => false, 'requires_reference' => true],
            ['code' => 'MOBILE_MONEY_AIRTEL', 'name' => 'Airtel Money', 'category' => $mobile, 'affects_cash_balance' => false, 'requires_reference' => true],
            ['code' => 'CHECK', 'name' => 'Chèque', 'category' => $bank, 'affects_cash_balance' => false, 'requires_reference' => true],
            ['code' => 'BANK_TRANSFER', 'name' => 'Virement bancaire', 'category' => $bank, 'affects_cash_balance' => false, 'requires_reference' => true],
            [
                'code' => 'PARTNER_COVERAGE', 'name' => 'Prise en charge partenaire',
                'category' => PaymentMethodCategory::Coverage->value,
                'affects_cash_balance' => false, 'requires_reference' => false,
            ],
            [
                'code' => 'OTHER', 'name' => 'Autre',
                'category' => PaymentMethodCategory::Other->value,
                'affects_cash_balance' => false, 'requires_reference' => false,
            ],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => $method['code']],
                [...$method, 'active' => true],
            );
        }

        // "Mobile money" is a category, not a tender: the former generic entry
        // is replaced by one row per operator, each reconciled on its own
        // account. It is dropped where it never served, and only kept —
        // deactivated — when a payment still points at it (ADR-010).
        $generic = PaymentMethod::query()->where('code', 'MOBILE_MONEY')->first();

        if ($generic && $generic->payments()->exists()) {
            $generic->update(['active' => false]);
        } elseif ($generic) {
            // Raw delete on purpose: the model guards deletion by design
            // (ProtectsFinancialRecord), and that guard is exactly what must
            // hold for every row a payment points at — which this one doesn't.
            DB::table('payment_methods')->where('id', $generic->id)->delete();
        }
    }
}
