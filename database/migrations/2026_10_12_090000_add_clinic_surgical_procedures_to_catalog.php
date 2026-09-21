<?php

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\StaffCoveragePolicy;
use App\Support\SurgeryReferenceData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le récapitulatif « Revenus » de la clinique (2026-09-20) nomme onze actes que
 * le bloc pratique et que le référentiel ne portait pas.
 *
 * Un site déjà en production ne rejoue pas `ClinicalServiceCatalogSeeder`
 * (ADR-064) : la migration crée donc ce qui manque, et **seulement** ce qui
 * manque — un code déjà présent n'est jamais réécrit, et aucun tarif n'est
 * inventé (ADR-024 : le prix appartient au Super Admin).
 */
return new class extends Migration
{
    private const ADDED = [
        'SURG-ABCES', 'SURG-ECTOPIE-TESTICULAIRE', 'SURG-FURONCLES',
        'SURG-HERNIE-INGUINALE', 'SURG-HERNIE-INGUINO-SCROTALE',
        'SURG-INVAGINATION-INTESTINALE', 'SURG-KYSTE-SOUS-CUTANE',
        'SURG-PLAIE-LINEAIRE', 'SURG-TORSION-CORDON',
        'SURG-VOLVULUS-INTESTINAL', 'SURG-CYSTOSTOMIE-DERIVATION',
    ];

    public function up(): void
    {
        // Elle **complète** un référentiel existant : sur une base qui n'a
        // encore aucun acte de bloc (site neuf, base de test), il n'y a rien à
        // compléter — c'est `ClinicalServiceCatalogSeeder` qui le pose en
        // entier. Sans cette garde, la migration inventait un demi-catalogue
        // là où personne n'en avait demandé.
        if (! DB::table('catalog_items')->where('module', CatalogModule::Surgery->value)->exists()) {
            return;
        }

        $names = collect(SurgeryReferenceData::procedures())->pluck('name', 'code');
        $now = now();

        foreach (self::ADDED as $code) {
            if (DB::table('catalog_items')->where('code', $code)->exists()) {
                continue;
            }

            DB::table('catalog_items')->insert([
                'uuid' => (string) Str::uuid(),
                'code' => $code,
                'name' => $names[$code],
                'type' => CatalogItemType::Service->value,
                'module' => CatalogModule::Surgery->value,
                'unit' => 'intervention',
                'description' => 'Intervention chirurgicale issue du référentiel validé par la clinique.',
                'billable' => true,
                'stockable' => false,
                'reception_selectable' => false,
                'staff_coverage_policy' => StaffCoveragePolicy::Unclassified->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Pas de retour arrière : un acte déjà utilisé par un dossier chirurgical
     * ne se supprime pas (ADR-010), et son tarif éventuel non plus.
     */
    public function down(): void {}
};
