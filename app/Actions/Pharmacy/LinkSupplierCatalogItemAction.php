<?php

namespace App\Actions\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-097 — spec §5 "liaison": links one parsed supplier catalog row to an
 * existing clinic Medicine, then immediately records the versioned quoted
 * price it carries — one atomic operation instead of two separate manual
 * steps. Linking never creates a Medicine/CatalogItem itself (the clinic
 * decides what to stock, spec §4); it only associates an already-existing
 * one with what this supplier proposes.
 */
class LinkSupplierCatalogItemAction
{
    public function __construct(private readonly SetMedicineSupplierOfferAction $setOffer) {}

    public function execute(SupplierCatalogItem $item, Medicine $medicine, string $reason, User $actor): MedicineSupplierOffer
    {
        return DB::transaction(function () use ($item, $medicine, $reason, $actor) {
            $item = SupplierCatalogItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->loadMissing('catalog.supplier');

            $offer = $this->setOffer->execute(
                medicine: $medicine,
                supplier: $item->catalog->supplier,
                quotedPrice: (string) $item->supplier_price,
                reason: $reason,
                actor: $actor,
                supplierReference: $item->reference,
                sourceCatalogItem: $item,
            );

            $item->update(['linked_medicine_id' => $medicine->getKey()]);

            return $offer;
        });
    }
}
