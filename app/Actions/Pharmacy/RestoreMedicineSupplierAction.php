<?php

namespace App\Actions\Pharmacy;

use App\Models\MedicineSupplier;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class RestoreMedicineSupplierAction
{
    public function execute(MedicineSupplier $supplier, CatalogActor $actor): MedicineSupplier
    {
        if ($actor->cannot('medicine_suppliers.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer ce fournisseur.');
        }

        $supplier->updated_by = $actor->localUserId();
        $supplier->restore();

        return $supplier;
    }
}
