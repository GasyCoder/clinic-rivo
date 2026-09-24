<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierCatalogPrices;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098 — un catalogue fournisseur part à la corbeille avec un motif ;
 * ADR-183 — les prix d'achat qu'il avait fournis cessent d'être en cours.
 */
class ArchiveSupplierCatalogAction
{
    public function __construct(private readonly SupplierCatalogPrices $prices) {}

    public function execute(SupplierCatalog $catalog, string $reason, CatalogActor $actor): SupplierCatalog
    {
        if ($actor->cannot('supplier_catalogs.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver ce catalogue.');
        }

        return DB::transaction(function () use ($catalog, $reason, $actor): SupplierCatalog {
            // Archiving the active catalog clears active_key first so the
            // unique(medicine_supplier_id, active_key) constraint never blocks
            // a later activation of a different catalog. This must be a real,
            // separate write: SoftDeletable::runSoftDelete() only ever persists
            // deleted_at/updated_at/deleted_by/delete_reason, so setting
            // active_key on the in-memory model and then calling delete() would
            // silently never save it.
            if ($catalog->isActive()) {
                $catalog->update(['active_key' => null]);
            }

            $catalog->delete_reason = trim($reason);
            $catalog->delete();

            // Le document retiré, ses prix ne sont plus proposés nulle part :
            // ni au comparateur, ni à la commande. Ils restent lisibles, clos.
            $this->prices->withdraw($catalog->items()->withTrashed()->pluck('id'), $actor);

            return $catalog;
        });
    }
}
