<?php

namespace App\Actions\Pharmacy\Concerns;

use App\Actions\Pharmacy\CreateMedicineFromSupplierCatalogAction;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — an order line names either a medicine the clinic already holds,
 * or a line of the supplier's catalogue it has not taken up yet. Creating
 * and correcting an order resolve it the same way, so the two can never
 * accept different things.
 */
trait ResolvesOrderedMedicine
{
    /** @param array{medicine_uuid?: ?string, supplier_catalog_item_uuid?: ?string} $line */
    private function resolveMedicine(array $line, MedicineSupplier $supplier, CatalogActor $actor): Medicine
    {
        if (filled($line['medicine_uuid'] ?? null)) {
            return Medicine::query()->where('uuid', $line['medicine_uuid'])->firstOrFail();
        }

        $item = SupplierCatalogItem::query()
            ->where('uuid', $line['supplier_catalog_item_uuid'])
            ->firstOrFail();

        // The line must belong to this supplier: its uuid is public, and
        // ordering another supplier's catalogue row would attach a product
        // to a folder that never proposed it.
        if ($item->catalog()->value('medicine_supplier_id') !== $supplier->getKey()) {
            throw ValidationException::withMessages([
                'lines' => 'Cette ligne de catalogue appartient à un autre fournisseur.',
            ]);
        }

        return app(CreateMedicineFromSupplierCatalogAction::class)->execute($item, $actor);
    }
}
