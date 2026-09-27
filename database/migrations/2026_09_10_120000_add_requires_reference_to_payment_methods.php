<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a tender carries an external transaction reference the cashier must
 * type in — the MVola/Orange Money/Airtel Money transaction number, a cheque
 * number, a transfer reference. Cash carries none: its own generated payment
 * number is its only reference.
 *
 * An explicit per-method flag, never deduced from the code or the label
 * (same principle as staff coverage policies, ADR-052), so each site can
 * decide for the tenders it adds itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->boolean('requires_reference')->default(false)->after('affects_cash_balance');
        });

        // Classified from the codes this application seeds itself; anything a
        // site added on its own stays false until it says otherwise.
        DB::table('payment_methods')
            ->whereIn('code', [
                'MOBILE_MONEY', 'MOBILE_MONEY_ORANGE', 'MOBILE_MONEY_MVOLA', 'MOBILE_MONEY_AIRTEL',
                'CHECK', 'BANK_TRANSFER',
            ])
            ->update(['requires_reference' => true]);
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropColumn('requires_reference');
        });
    }
};
