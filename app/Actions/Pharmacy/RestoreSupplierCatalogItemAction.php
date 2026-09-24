<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierCatalogPrices;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098/ADR-009 — a line withdrawn by mistake comes back as it was;
 * ADR-183 — with the purchase price it provided, unless a price set since
 * has replaced it, or its whole catalogue is still in the trash.
 */
class RestoreSupplierCatalogItemAction
{
    public function __construct(private readonly SupplierCatalogPrices $prices) {}

    public function execute(SupplierCatalogItem $item, CatalogActor $actor): SupplierCatalogItem
    {
        if ($actor->cannot('supplier_catalogs.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer une ligne de catalogue.');
        }

        return DB::transaction(function () use ($item, $actor): SupplierCatalogItem {
            $item = SupplierCatalogItem::withTrashed()->lockForUpdate()->findOrFail($item->id);
            $item->restore();

            // Le catalogue d'une ligne retirée avec lui n'est pas restauré par
            // elle : `catalog` reste vide, et aucun prix ne revient.
            $this->prices->reinstate(collect([$item->load('catalog')]), $actor);

            return $item->fresh();
        });
    }
}
