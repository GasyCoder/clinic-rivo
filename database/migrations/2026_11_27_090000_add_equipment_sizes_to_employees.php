<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche du personnel de la clinique : tailles de tenue, matériel remis et second
 * contact. Informations déclaratives et facultatives, sans aucun calcul.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('phone_secondary', 50)->nullable()->after('phone');
            $table->string('tshirt_size', 50)->nullable()->after('blouse');
            $table->string('blouse_size', 50)->nullable()->after('tshirt_size');
            $table->string('bloc_outfit', 50)->nullable()->after('blouse_size');
            $table->string('shoe_size', 50)->nullable()->after('bloc_outfit');
            $table->string('scrub_cap', 50)->nullable()->after('shoe_size');
            $table->string('clog', 50)->nullable()->after('scrub_cap');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['phone_secondary', 'tshirt_size', 'blouse_size', 'bloc_outfit', 'shoe_size', 'scrub_cap', 'clog']);
        });
    }
};
