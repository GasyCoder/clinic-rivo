<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-203 — conclure une consultation ne demande plus
 * que la conduite à tenir. L'état du patient à la sortie devient facultatif :
 * une absence reste une absence (`null`), jamais une chaîne vide (ADR-077).
 *
 * Seules les chaînes vides déjà écrites deviennent `null` ; aucune valeur
 * renseignée n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_discharges', function (Blueprint $table): void {
            $table->text('patient_condition')->nullable()->change();
        });

        DB::table('medical_discharges')->where('patient_condition', '')->update(['patient_condition' => null]);
    }

    public function down(): void
    {
        DB::table('medical_discharges')->whereNull('patient_condition')->update(['patient_condition' => '']);

        Schema::table('medical_discharges', function (Blueprint $table): void {
            $table->text('patient_condition')->nullable(false)->change();
        });
    }
};
