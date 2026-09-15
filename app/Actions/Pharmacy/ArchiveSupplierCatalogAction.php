<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class ArchiveSupplierCatalogAction
{
    public function execute(SupplierCatalog $catalog, string $reason, CatalogActor $actor): SupplierCatalog
    {
        if ($actor->cannot('supplier_catalogs.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver ce catalogue.');
        }

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

        return $catalog;
    }
}
