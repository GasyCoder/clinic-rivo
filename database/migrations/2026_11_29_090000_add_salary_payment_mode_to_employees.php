<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Feuille de paie de la clinique : la rémunération est versée par BNI, BOA, ACCESS,
 * Mobile Money ou en espèces. Le mode se déclare sur la fiche ; les banques BNI et BOA
 * existent déjà, ACCESS et la fonction « Tsarashop » (liste du personnel) sont ajoutées
 * si elles manquent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('salary_payment_mode', 20)->nullable()->after('bank_account_holder');
            $table->string('mobile_money_number', 50)->nullable()->after('salary_payment_mode');
        });

        $now = now();

        if (! DB::table('banks')->where('code', 'ACCESS')->exists()) {
            DB::table('banks')->insert([
                'uuid' => (string) Str::uuid(), 'code' => 'ACCESS', 'name' => 'Access Banque Madagascar',
                'normalized_name' => 'access banque madagascar', 'active' => true,
                'position' => (int) DB::table('banks')->max('position') + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        if (! DB::table('hr_reference_values')->where('type', 'JOB_TITLE')->where('code', 'TSARASHOP')->exists()) {
            DB::table('hr_reference_values')->insert([
                'uuid' => (string) Str::uuid(), 'type' => 'JOB_TITLE', 'code' => 'TSARASHOP', 'label' => 'Tsarashop',
                'active' => true, 'position' => (int) DB::table('hr_reference_values')->where('type', 'JOB_TITLE')->max('position') + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['salary_payment_mode', 'mobile_money_number']);
        });
    }
};
