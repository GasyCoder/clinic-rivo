<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-183 — un prix d'achat venu d'un catalogue fournisseur ne survit pas au
 * retrait de ce catalogue.
 *
 * Désormais, mettre un catalogue ou une ligne à la corbeille clôt les prix
 * qu'il avait fournis. Ceux qui avaient été retirés avant cette règle sont
 * restés « en cours » — sur Ambondromamy, les cinq prix de Pharmalife encore
 * proposés au comparateur alors que ses catalogues sont à la corbeille. On
 * leur applique la même règle, à la date du retrait.
 *
 * Rien n'est supprimé : le prix est clos (daté, libéré), il reste lisible
 * dans l'historique, et chaque clôture est tracée dans l'audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $offers = DB::table('medicine_supplier_offers as o')
            ->join('supplier_catalog_items as i', 'i.id', '=', 'o.supplier_catalog_item_id')
            ->join('supplier_catalogs as c', 'c.id', '=', 'i.supplier_catalog_id')
            ->where('o.active_key', 'CURRENT')
            ->where(fn ($query) => $query->whereNotNull('c.deleted_at')->orWhereNotNull('i.deleted_at'))
            ->get([
                'o.id', 'o.uuid', 'o.quoted_price', 'o.effective_from',
                'c.deleted_at as catalog_deleted_at', 'i.deleted_at as item_deleted_at',
            ]);

        foreach ($offers as $offer) {
            // Clos au moment du retrait, jamais avant que le prix ait commencé.
            $withdrawnAt = collect([$offer->catalog_deleted_at, $offer->item_deleted_at])->filter()->min();
            $endedAt = max((string) $withdrawnAt, (string) $offer->effective_from);

            DB::transaction(function () use ($offer, $endedAt): void {
                DB::table('medicine_supplier_offers')->where('id', $offer->id)->update([
                    'effective_until' => $endedAt,
                    'active_key' => null,
                    'updated_at' => now(),
                ]);

                DB::table('audit_logs')->insert([
                    'uuid' => (string) Str::uuid(),
                    'action' => 'pharmacy.supplier_offer.withdraw',
                    'module' => 'pharmacy',
                    'entity_type' => 'App\\Models\\MedicineSupplierOffer',
                    'entity_id' => $offer->id,
                    'entity_uuid' => $offer->uuid,
                    'old_values' => json_encode(['active_key' => 'CURRENT', 'effective_until' => null, 'quoted_price' => $offer->quoted_price]),
                    'new_values' => json_encode(['active_key' => null, 'effective_until' => $endedAt]),
                    'reason' => 'Le catalogue fournisseur qui fournissait ce prix est à la corbeille : le prix n’est plus en cours (ADR-183).',
                    'created_at' => now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        // Rien à rouvrir d'office : un prix revient en restaurant son catalogue,
        // qui ne le rétablit que si aucune décision prise depuis ne l'a remplacé.
    }
};
