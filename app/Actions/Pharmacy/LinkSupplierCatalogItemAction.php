<?php

namespace App\Actions\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function execute(SupplierCatalogItem $item, Medicine $medicine, string $reason, CatalogActor $actor): MedicineSupplierOffer
    {
        return DB::transaction(function () use ($item, $medicine, $reason, $actor) {
            $item = SupplierCatalogItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->loadMissing('catalog.supplier');

            // A catalogue line may arrive without a price (ADR-098): the
            // link is what creates the purchase price, so it cannot be made
            // from an amount nobody gave.
            if (! filled($item->supplier_price)) {
                throw ValidationException::withMessages([
                    'supplier_price' => 'Cette ligne du catalogue n’a pas de prix : indiquez le prix du fournisseur avant de la rattacher à un médicament.',
                ]);
            }

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
