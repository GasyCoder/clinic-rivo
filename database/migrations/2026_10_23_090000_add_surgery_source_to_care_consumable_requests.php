<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-169 — le matériel utilisé au bloc rejoint le circuit des consommables
 * Soins (ADR-072) et Maternité (ADR-142) : même demande, même file Pharmacie,
 * même sortie de stock, même facturation à la Caisse. `source_module` vaut
 * alors `SURGERY`, et la demande désigne le dossier du bloc qui l'a émise.
 *
 * Aucune ligne existante n'est réécrite. Les consommables saisis au bloc avant
 * ce circuit restent dans `surgical_consumables`, lisibles tels quels comme
 * lignes « hors stock » : les rattacher après coup à un produit reviendrait à
 * inventer ce qui a été utilisé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_consumable_requests', function (Blueprint $table) {
            $table->foreignId('surgical_request_id')->nullable()->after('maternity_record_id')
                ->constrained('surgical_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('care_consumable_requests', function (Blueprint $table) {
            $table->dropForeign(['surgical_request_id']);
            $table->dropColumn('surgical_request_id');
        });
    }
};
