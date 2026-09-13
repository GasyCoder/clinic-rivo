<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\LocalOnly;
use Illuminate\Database\Seeder;

/**
 * Everything a developer needs after `php artisan migrate:fresh --seed`.
 *
 * Called by DatabaseSeeder only in the local environment. Every seeder here
 * is idempotent and refuses to run in production; none of them creates a
 * patient, an episode, an invoice or a payment — only reference data, test
 * stock and the local test accounts.
 */
class DevelopmentSeeder extends Seeder
{
    use LocalOnly;

    public function run(): void
    {
        $this->ensureLocal();

        if (config('rivo.site.type') === 'admin') {
            $this->call(DevelopmentTestAccountSeeder::class);

            return;
        }

        $this->call([
            DevelopmentTestAccountSeeder::class,          // auteur traçable des référentiels
            ClinicalServiceCatalogSeeder::class,          // prestations + tarifs Standard / Mutuelle
            DevelopmentMutualOrganizationSeeder::class,   // mutuelles et taux de couverture
            DevelopmentParaclinicalCatalogSeeder::class,  // analyses, ECG, échographies de base
            DevelopmentLegacyAnalysisCatalogSeeder::class, // 719 analyses historiques
            DevelopmentDiagnosticCatalogSeeder::class,    // diagnostics courants
            DevelopmentMedicineStockSeeder::class,        // médicaments, lots, fournisseurs, stock
            DevelopmentCashRegisterSeeder::class,         // Caisse 1 / Caisse 2
        ]);
    }
}
