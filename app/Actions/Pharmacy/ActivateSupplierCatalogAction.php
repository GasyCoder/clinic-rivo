<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-097 — spec §2: "savoir quel catalogue est actuellement actif", one at
 * a time per supplier (DB-enforced by the unique(medicine_supplier_id,
 * active_key) constraint). Deactivating the previous one and activating the
 * new one happen in a single transaction so the constraint is never
 * transiently violated.
 */
class ActivateSupplierCatalogAction
{
    public function execute(SupplierCatalog $catalog, CatalogActor $actor): SupplierCatalog
    {
        if ($actor->cannot('supplier_catalogs.update')) {
            throw new AuthorizationException('Vous ne pouvez pas activer ce catalogue.');
        }

        return DB::transaction(function () use ($catalog, $actor): SupplierCatalog {
            $catalog = SupplierCatalog::query()->lockForUpdate()->findOrFail($catalog->id);

            $previouslyActive = SupplierCatalog::query()
                ->where('medicine_supplier_id', $catalog->medicine_supplier_id)
                ->where('active_key', 'ACTIVE')
                ->where('id', '!=', $catalog->id)
                ->lockForUpdate()
                ->first();

            $previouslyActive?->update(['active_key' => null, 'updated_by' => $actor->localUserId()]);

            $catalog->update(['active_key' => 'ACTIVE', 'updated_by' => $actor->localUserId()]);

            return $catalog;
        });
    }
}
