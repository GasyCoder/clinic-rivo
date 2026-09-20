<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-136 — « Syana Press / Dépôt Provera » est un acte de la liste Maternité
 * du propriétaire. Il était rangé dans le module Planning familial, qui n'a
 * aucun espace de travail : il n'était donc proposé nulle part.
 *
 * Mise à jour seulement, comme la migration de routage Maternité : le
 * catalogue est écrit par le Super Admin (ADR-024), donc aucune ligne n'est
 * créée ici. Les actes « Nursie », « IEC » et « Utilisation Aspirateur bébé »
 * se créent depuis Désignations & tarifs, sur chaque site.
 *
 * Le code ne change pas — il est l'identité stable de la désignation. Ne sont
 * touchées que la ligne encore dans son module d'origine et les deux libellés
 * encore au nom de l'ancien jeu de données ; un choix d'administrateur est
 * laissé intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('catalog_items')
            ->where('code', 'FP-INJECTABLE')
            ->where('module', 'FAMILY_PLANNING')
            ->whereNull('deleted_at')
            ->update([
                'module' => 'MATERNITY',
                'reception_selectable' => true,
                'reception_routing_mode' => 'MATERNITY_DIRECT',
                'updated_at' => now(),
            ]);

        foreach (['MAT-DOPPLER' => ['Doppler', 'Utilisation Echo Doppler'], 'MAT-PHOTOTHERAPY' => ['Photothérapie', 'Utilisation Photothérapie']] as $code => [$old, $new]) {
            DB::table('catalog_items')
                ->where('code', $code)
                ->where('name', $old)
                ->whereNull('deleted_at')
                ->update(['name' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('catalog_items')
            ->where('code', 'FP-INJECTABLE')
            ->where('module', 'MATERNITY')
            ->update([
                'module' => 'FAMILY_PLANNING',
                'reception_selectable' => false,
                'reception_routing_mode' => null,
                'updated_at' => now(),
            ]);

        foreach (['MAT-DOPPLER' => ['Utilisation Echo Doppler', 'Doppler'], 'MAT-PHOTOTHERAPY' => ['Utilisation Photothérapie', 'Photothérapie']] as $code => [$from, $to]) {
            DB::table('catalog_items')->where('code', $code)->where('name', $from)->update(['name' => $to, 'updated_at' => now()]);
        }
    }
};
