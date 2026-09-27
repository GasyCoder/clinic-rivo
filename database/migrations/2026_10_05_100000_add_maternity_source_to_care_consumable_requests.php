<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le matériel utilisé en Maternité rejoint le circuit des consommables Soins
 * (ADR-142) : même demande, même file Pharmacie, même sortie de stock, même
 * facturation à la Caisse. Seule la **provenance** se distingue.
 *
 * `care_orientation_id` désigne l'orientation du service demandeur — Soins ou
 * Maternité — sans changer de nom : c'est le lien porteur que la file, la
 * facturation et la sortie de stock lisent déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_consumable_requests', function (Blueprint $table) {
            $table->string('source_module', 30)->default('CARE')->after('request_number')->index();
            $table->foreignId('maternity_record_id')->nullable()->after('care_record_id')
                ->constrained('maternity_records')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('care_consumable_requests', function (Blueprint $table) {
            $table->dropForeign(['maternity_record_id']);
            $table->dropIndex(['source_module']);
            $table->dropColumn(['source_module', 'maternity_record_id']);
        });
    }
};
