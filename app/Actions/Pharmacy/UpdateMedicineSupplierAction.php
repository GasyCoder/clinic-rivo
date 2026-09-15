<?php

namespace App\Actions\Pharmacy;

use App\Models\MedicineSupplier;
use App\Services\Catalog\CatalogActor;

/**
 * ADR-098 — corrects a supplier's identity. The code is deliberately not
 * editable: medicine imports and supplier imports designate a supplier by
 * its code, so changing it would silently detach them.
 */
class UpdateMedicineSupplierAction
{
    private const FIELDS = ['name', 'contact_name', 'phone', 'email', 'address'];

    /** @param array<string, mixed> $data only the keys present are changed */
    public function execute(MedicineSupplier $supplier, array $data, CatalogActor $actor): MedicineSupplier
    {
        foreach (array_intersect_key($data, array_flip(self::FIELDS)) as $field => $value) {
            $value = trim((string) $value);
            $supplier->{$field} = $value === '' && $field !== 'name' ? null : $value;
        }

        $supplier->updated_by = $actor->localUserId();
        $supplier->save();

        return $supplier;
    }
}
