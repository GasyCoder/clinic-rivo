<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** ADR-098/ADR-009 — a line withdrawn by mistake comes back as it was. */
class RestoreSupplierCatalogItemAction
{
    public function execute(SupplierCatalogItem $item, CatalogActor $actor): SupplierCatalogItem
    {
        if ($actor->cannot('supplier_catalogs.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer une ligne de catalogue.');
        }

        return DB::transaction(function () use ($item): SupplierCatalogItem {
            $item = SupplierCatalogItem::withTrashed()->lockForUpdate()->findOrFail($item->id);
            $item->restore();

            return $item->fresh();
        });
    }
}
