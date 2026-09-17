<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Vide entièrement le domaine Pharmacie d'une base de développement, pour
 * repartir de zéro avec de vraies données : fournisseurs, catalogues, prix,
 * commandes, réceptions, factures fournisseur, stock, lots, mouvements,
 * médicaments et familles.
 *
 * Ce que la Pharmacie a produit ailleurs part avec elle — délivrances,
 * lignes d'ordonnance citant un médicament, prestations facturées de
 * médicaments et leurs factures. Une facture déjà encaissée est en revanche
 * conservée et signalée : supprimer un paiement réel n'est pas un ménage de
 * données, et une remise à zéro complète (`migrate:fresh`) est alors le bon
 * outil.
 *
 * Refusée hors `local` / `testing` : aucune base de production ne doit
 * pouvoir être vidée par une commande (ADR-086).
 */
class ResetPharmacyDomain extends Command
{
    protected $signature = 'rivo:pharmacy-reset {--force : Ne pas demander de confirmation}';

    protected $description = 'Vide le domaine Pharmacie (fournisseurs, achats, stock, médicaments) d’une base locale';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refusé : cette commande ne s’exécute qu’en local.');

            return self::FAILURE;
        }

        $database = DB::getDatabaseName();

        if (! $this->option('force') && ! $this->confirm("Vider tout le domaine Pharmacie de « {$database} » ? Cette action est irréversible.")) {
            return self::SUCCESS;
        }

        $kept = 0;
        $counts = [];

        DB::transaction(function () use (&$counts, &$kept): void {
            $medicineItemIds = DB::table('catalog_items')->where('type', 'MEDICINE')->pluck('id');
            $medicineIds = DB::table('medicines')->pluck('id');
            $billableIds = $medicineItemIds->isEmpty()
                ? collect()
                : DB::table('billable_items')->whereIn('catalog_item_id', $medicineItemIds)->pluck('id');
            $dispenseInvoiceIds = $this->exists('pharmacy_dispenses')
                ? DB::table('pharmacy_dispenses')->whereNotNull('invoice_id')->pluck('invoice_id')
                : collect();

            // Une facture encaissée n'est jamais détruite ici.
            $paidInvoiceIds = DB::table('payments')->whereIn('invoice_id', $dispenseInvoiceIds)->pluck('invoice_id')->unique();
            $invoiceIds = $dispenseInvoiceIds->diff($paidInvoiceIds)->unique()->values();
            $kept = $paidInvoiceIds->count();

            $counts['délivrances'] = $this->wipe([
                'pharmacy_dispense_allocations', 'pharmacy_dispense_lot_reservations',
                'pharmacy_dispense_events', 'pharmacy_dispense_lines', 'pharmacy_dispenses',
                'medicine_stock_reservations',
            ]);

            $counts['consommables Soins'] = $this->wipe([
                'care_consumable_allocations', 'care_consumable_request_lines',
                'care_consumable_requests', 'care_act_consumables',
            ]);

            $counts['lignes d’ordonnance'] = $this->exists('prescription_lines')
                ? DB::table('prescription_lines')->whereNotNull('medicine_id')->delete()
                : 0;

            $counts['approvisionnement'] = $this->wipe([
                'supplier_invoice_lines', 'supplier_invoices',
                'goods_receipt_lines', 'goods_receipts',
                'purchase_order_lines', 'purchase_orders',
                'supplier_catalog_items', 'supplier_catalogs',
                'medicine_supplier_offers', 'medicine_supplier', 'medicine_suppliers',
            ]);

            $counts['stock'] = $this->wipe(['pharmacy_stock_alerts', 'pharmacy_stock_movements', 'medicine_lots']);

            $removed = 0;

            if ($billableIds->isNotEmpty()) {
                $removed += DB::table('invoice_lines')->whereIn('billable_item_id', $billableIds)->delete();

                if ($this->exists('staff_block_credit_movements')) {
                    DB::table('staff_block_credit_movements')->whereIn('billable_item_id', $billableIds)->delete();
                }

                $removed += DB::table('billable_items')->whereIn('id', $billableIds)->delete();
            }

            if ($invoiceIds->isNotEmpty()) {
                DB::table('invoice_lines')->whereIn('invoice_id', $invoiceIds)->delete();
                $removed += DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
            }

            $counts['facturation médicaments'] = $removed;

            $counts['médicaments'] = $this->wipe(['medicines', 'medicine_categories']);

            if ($medicineItemIds->isNotEmpty()) {
                DB::table('catalog_tariffs')->whereIn('catalog_item_id', $medicineItemIds)->delete();
                $counts['fiches du référentiel'] = DB::table('catalog_items')->whereIn('id', $medicineItemIds)->delete();
            }

            unset($medicineIds);
        });

        // Les fichiers des catalogues et des factures fournisseur.
        $files = 0;

        foreach (Storage::disk('local')->allFiles('suppliers') as $file) {
            Storage::disk('local')->delete($file);
            $files++;
        }

        Storage::disk('local')->deleteDirectory('suppliers');

        foreach ($counts as $label => $rows) {
            $this->line(sprintf('  %-26s %d ligne(s) supprimée(s)', $label, $rows));
        }

        $this->line(sprintf('  %-26s %d fichier(s) supprimé(s)', 'documents fournisseurs', $files));

        if ($kept > 0) {
            $this->warn("{$kept} facture(s) Pharmacie déjà encaissée(s) ont été conservées avec leurs paiements.");
        }

        $this->info("Domaine Pharmacie vidé sur « {$database} ».");

        return self::SUCCESS;
    }

    /** @param array<int, string> $tables */
    private function wipe(array $tables): int
    {
        $deleted = 0;

        foreach ($tables as $table) {
            if ($this->exists($table)) {
                $deleted += DB::table($table)->delete();
            }
        }

        return $deleted;
    }

    private function exists(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
