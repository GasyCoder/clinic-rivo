<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The reference lists a clinic site needs from its first day in production.
 *
 * Called by DatabaseSeeder on a clinic deployment outside local (locally,
 * DevelopmentSeeder plays the same seeders with a test author and prices).
 * Every seeder here only adds what is missing and never rewrites an existing
 * row, so `db:seed --force` can be replayed safely.
 *
 * Deliberately absent: test accounts, provisional tariffs (set by the Super
 * Admin, ADR-024), named cash desks (ADR-058) and demo pharmacy stock (ADR-086).
 * Rows are written without an author: a fresh site has no account yet.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        if (config('rivo.site.type') !== 'clinic') {
            return;
        }

        $this->call([
            ClinicalServiceCatalogSeeder::class,          // désignations, sans tarif
            DevelopmentMutualOrganizationSeeder::class,   // mutuelles communiquées par le client
            DevelopmentParaclinicalCatalogSeeder::class,  // ECG, échographies, analyses de base
            DevelopmentLegacyAnalysisCatalogSeeder::class, // 719 analyses historiques
            LabMicrobiologySeeder::class,                 // familles, germes, antibiotiques
            LabSampleSeeder::class,                       // types de tube et de prélèvement
            DevelopmentDiagnosticCatalogSeeder::class,    // diagnostics courants, sans code
        ]);
    }
}
