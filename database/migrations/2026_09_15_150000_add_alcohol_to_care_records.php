<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consommation d'alcool déclarée, à côté du tabagisme de l'ADR-032.
 *
 * Nullable comme `smoker` : trois états distincts et non deux — non
 * renseigné, non, oui. Un patient à qui on n'a pas posé la question n'est
 * pas un patient qui a répondu « non », et confondre les deux ferait lire
 * une absence de relevé comme une réponse négative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->boolean('alcohol')->nullable()->after('smoker');
        });
    }

    public function down(): void
    {
        Schema::table('care_records', function (Blueprint $table) {
            $table->dropColumn('alcohol');
        });
    }
};
