<?php

use App\Enums\PaymentMethodCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->string('category', 20)
                ->default(PaymentMethodCategory::Other->value)
                ->after('name');
        });

        // Existing rows are classified from the codes this application seeds
        // itself. Anything else stays OTHER rather than being guessed from a
        // free-text label — the site reclassifies it explicitly.
        $known = [
            PaymentMethodCategory::Cash->value => ['CASH'],
            PaymentMethodCategory::MobileMoney->value => [
                'MOBILE_MONEY', 'MOBILE_MONEY_ORANGE', 'MOBILE_MONEY_MVOLA', 'MOBILE_MONEY_AIRTEL',
            ],
            PaymentMethodCategory::Bank->value => ['BANK_TRANSFER', 'CHECK'],
            PaymentMethodCategory::Coverage->value => ['PARTNER_COVERAGE'],
        ];

        foreach ($known as $category => $codes) {
            DB::table('payment_methods')->whereIn('code', $codes)->update(['category' => $category]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
