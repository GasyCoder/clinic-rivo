<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-187 — le Super Admin du portail agit sur les Ressources humaines d'un
 * site sans y avoir de compte : là où une fiche affiche son auteur (« décidé
 * par », « déposé par »), l'UUID et le nom du Super Admin sont conservés, sur
 * le modèle des commandes et factures fournisseur (ADR-098).
 *
 * `staff_block_credit_movements.created_by` devient facultatif pour la même
 * raison : une allocation décidée depuis le portail n'a pas d'auteur local.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const ATTRIBUTIONS = [
        'leave_requests' => ['decided', 'cancelled'],
        'hr_documents' => ['uploaded'],
        'generated_documents' => ['generated'],
        'staff_block_credit_movements' => ['created'],
    ];

    public function up(): void
    {
        foreach (self::ATTRIBUTIONS as $table => $verbs) {
            Schema::table($table, function (Blueprint $blueprint) use ($verbs): void {
                foreach ($verbs as $verb) {
                    $blueprint->uuid("external_{$verb}_by_uuid")->nullable();
                    $blueprint->string("external_{$verb}_by_name", 150)->nullable();
                }
            });
        }

        Schema::table('staff_block_credit_movements', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        foreach (self::ATTRIBUTIONS as $table => $verbs) {
            Schema::table($table, function (Blueprint $blueprint) use ($verbs): void {
                foreach ($verbs as $verb) {
                    $blueprint->dropColumn(["external_{$verb}_by_uuid", "external_{$verb}_by_name"]);
                }
            });
        }
    }
};
