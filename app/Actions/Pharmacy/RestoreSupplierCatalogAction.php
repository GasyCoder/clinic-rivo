<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class RestoreSupplierCatalogAction
{
    public function execute(SupplierCatalog $catalog, CatalogActor $actor): SupplierCatalog
    {
        if ($actor->cannot('supplier_catalogs.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer ce catalogue.');
        }

        $catalog->updated_by = $actor->localUserId();
        $catalog->restore();

        return $catalog;
    }
}
