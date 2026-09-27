<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un acte Maternité enregistré alimente le compte du patient (ADR-141).
 *
 * `billing_origin` dit **qui** a porté l'acte au compte :
 *
 * ```text
 * OWN      la Maternité l'a facturé à l'enregistrement — elle peut donc annuler
 *          cette facturation tant qu'elle n'est pas sur une facture
 * PLANNED  la Réception l'avait déjà facturé à l'arrivée : l'acte s'y rattache,
 *          et le retirer du dossier ne touche jamais ce que la Réception a facturé
 * ```
 *
 * Les actes déjà enregistrés n'ont ni l'un ni l'autre : rien n'est facturé
 * rétroactivement — ce serait inventer une facturation que personne n'a décidée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maternity_procedures', function (Blueprint $table) {
            $table->foreignId('billable_item_id')->nullable()->after('performed_at')
                ->constrained('billable_items')->nullOnDelete();
            $table->string('billing_origin', 10)->nullable()->after('billable_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('maternity_procedures', function (Blueprint $table) {
            $table->dropForeign(['billable_item_id']);
            $table->dropColumn(['billable_item_id', 'billing_origin']);
        });
    }
};
