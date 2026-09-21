<?php

use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Support\SurgeryReferenceData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-159 — la Réception inscrit un acte du bloc quand il est la raison de la
 * venue. Deux chemins mènent alors au bloc, et deux seulement : celui-ci, et
 * la conduite à tenir du médecin (ADR-084).
 *
 * Un site en production ne rejoue pas `ClinicalServiceCatalogSeeder`
 * (ADR-064) : la migration ouvre donc la sélection sur les actes déjà
 * présents.
 *
 * Elle liste les codes **explicitement**, depuis le référentiel des
 * interventions : un module `SURGERY` ne signifie pas « bloc » (ADR-052) —
 * `CONSULT-CHIR` et `PETITE-CHIR` appartiennent au même module sans être des
 * actes opératoires, et les router vers le bloc ouvrirait une file où
 * personne ne les attend. Sont écartés « Autres », qui n'a ni nom ni prix, et
 * la césarienne, qui reste soumise au workflow Maternité (ADR-067).
 *
 * Aucun tarif n'est créé : le prix appartient au Super Admin (ADR-024).
 */
return new class extends Migration
{
    private const EXCLUDED = ['SURG-OTHER', 'SURG-CESARIENNE'];

    /** @return list<string> */
    private function codes(): array
    {
        return array_values(array_diff(
            array_column(SurgeryReferenceData::procedures(), 'code'),
            self::EXCLUDED,
        ));
    }

    public function up(): void
    {
        DB::table('catalog_items')
            ->where('module', CatalogModule::Surgery->value)
            ->whereIn('code', $this->codes())
            ->whereNull('deleted_at')
            ->update([
                'reception_selectable' => true,
                'reception_routing_mode' => ReceptionRoutingMode::SurgeryDirect->value,
            ]);
    }

    public function down(): void
    {
        DB::table('catalog_items')
            ->where('module', CatalogModule::Surgery->value)
            ->where('reception_routing_mode', ReceptionRoutingMode::SurgeryDirect->value)
            ->update([
                'reception_selectable' => false,
                'reception_routing_mode' => null,
            ]);
    }
};
