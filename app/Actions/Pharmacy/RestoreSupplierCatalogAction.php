<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierCatalogPrices;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098 — un catalogue retiré par erreur revient tel qu'il était ;
 * ADR-183 — avec les prix d'achat qu'il fournissait, sauf ceux qu'une
 * décision prise depuis a remplacés.
 */
class RestoreSupplierCatalogAction
{
    public function __construct(private readonly SupplierCatalogPrices $prices) {}

    public function execute(SupplierCatalog $catalog, CatalogActor $actor): SupplierCatalog
    {
        if ($actor->cannot('supplier_catalogs.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer ce catalogue.');
        }

        return DB::transaction(function () use ($catalog, $actor): SupplierCatalog {
            $catalog->updated_by = $actor->localUserId();
            $catalog->restore();

            // Une ligne retirée à part reste retirée : seules les lignes
            // vivantes rendent leur prix.
            $this->prices->reinstate($catalog->items()->with('catalog')->get(), $actor);

            return $catalog;
        });
    }
}
