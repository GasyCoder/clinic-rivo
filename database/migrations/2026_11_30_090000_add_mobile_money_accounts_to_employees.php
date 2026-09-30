<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile Money : plusieurs comptes par employé (opérateur, numéro, nom du titulaire)
 * à la place d'un seul numéro. Un numéro déjà noté est repris tel quel, sans opérateur
 * ni titulaire : rien n'est deviné, le RH les complète.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->json('mobile_money_accounts')->nullable()->after('salary_payment_mode');
        });

        DB::table('employees')->whereNotNull('mobile_money_number')->where('mobile_money_number', '!=', '')
            ->orderBy('id')->each(function ($employee): void {
                DB::table('employees')->where('id', $employee->id)->update([
                    'mobile_money_accounts' => json_encode([['operator' => null, 'number' => $employee->mobile_money_number, 'holder' => null]]),
                ]);
            });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('mobile_money_number');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('mobile_money_number', 50)->nullable()->after('salary_payment_mode');
        });

        DB::table('employees')->whereNotNull('mobile_money_accounts')->orderBy('id')->each(function ($employee): void {
            $first = json_decode((string) $employee->mobile_money_accounts, true)[0]['number'] ?? null;
            DB::table('employees')->where('id', $employee->id)->update(['mobile_money_number' => $first]);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('mobile_money_accounts');
        });
    }
};
