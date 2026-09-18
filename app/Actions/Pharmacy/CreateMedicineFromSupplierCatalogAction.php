<?php

namespace App\Actions\Pharmacy;

use App\Actions\Catalog\CreateCatalogItemAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-098 — a line of a supplier catalogue becomes a clinic medicine at the
 * moment it is ordered.
 *
 * Ordering references a Medicine (purchase_order_lines.medicine_id), so a
 * product had to exist in the clinic catalogue *before* it could be
 * ordered — which is the wrong way round for a first purchase: what the
 * clinic will stock is precisely what it has decided to buy. The medicine
 * is therefore created here, from what the supplier's own catalogue says,
 * and nothing more:
 *
 *   - no selling price. The purchase price is not the selling price
 *     (ADR-024); inventing one at order time would be a made-up business
 *     rule. The item is billable but carries no tariff, so it cannot be
 *     dispensed or sold until someone sets that price — after reception.
 *   - no invented clinical attribute. DCI, form, dosage, manufacturer are
 *     left empty rather than guessed from a label; the pharmacist completes
 *     the record when the goods arrive.
 *
 * The supplier's quoted price, when the catalogue line carries one, is
 * recorded as its versioned purchase price (ADR-097) and the line is linked,
 * exactly as LinkSupplierCatalogItemAction would have done manually.
 */
class CreateMedicineFromSupplierCatalogAction
{
    public function __construct(
        private readonly CreateCatalogItemAction $createCatalogItem,
        private readonly SetMedicineSupplierOfferAction $setOffer,
    ) {}

    public function execute(SupplierCatalogItem $item, CatalogActor $actor): Medicine
    {
        if ($actor->cannot('medicines.create')) {
            throw new AuthorizationException('Vous ne pouvez pas ajouter un médicament au catalogue de la clinique.');
        }

        return DB::transaction(function () use ($item, $actor): Medicine {
            $item = SupplierCatalogItem::query()->lockForUpdate()->findOrFail($item->id);
            $item->loadMissing('catalog.supplier');

            if ($item->linked_medicine_id) {
                return Medicine::query()->findOrFail($item->linked_medicine_id);
            }

            // The same product offered by two suppliers is one product: it
            // is reused, and this supplier's price is simply added beside
            // the other's (ADR-097 allows several current offers). Creating
            // "Paracétamol 500 mg" twice because two folders name it would
            // split its stock, its history and its selling price in two.
            if ($existing = $this->existingMedicine($item->medicine_label)) {
                return $this->attach($item, $existing, $actor);
            }

            $catalogItem = $this->createCatalogItem->execute([
                'code' => $this->code($item),
                'name' => $item->medicine_label,
                'type' => CatalogItemType::Medicine->value,
                'module' => CatalogModule::Pharmacy->value,
                'unit' => filled($item->presentation) ? mb_substr(trim($item->presentation), 0, 40) : 'Unité',
                'billable' => true,
                'stockable' => true,
                'description' => filled($item->presentation) ? trim($item->presentation) : null,
            ], $actor);

            $medicine = Medicine::query()->create([
                'catalog_item_id' => $catalogItem->getKey(),
                // The label on a supplier invoice is a trade name, not a
                // DCI: deducing one would invent a clinical fact.
                'generic_name' => null,
                'form' => MedicineForm::Other,
                'strength' => null,
                'minimum_stock' => 0,
                'prescription_required' => false,
                'active' => true,
                'created_by' => $actor->localUserId(),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
                ...$actor->externalAttribution('updated'),
            ]);

            $medicine->suppliers()->syncWithoutDetaching([$item->catalog->supplier->getKey()]);
            $item->update(['linked_medicine_id' => $medicine->getKey()]);

            if (filled($item->supplier_price)) {
                $this->setOffer->execute(
                    medicine: $medicine,
                    supplier: $item->catalog->supplier,
                    quotedPrice: (string) $item->supplier_price,
                    reason: 'Prix repris du catalogue fournisseur à la commande',
                    actor: $actor,
                    supplierReference: $item->reference,
                    sourceCatalogItem: $item,
                );
            }

            return $medicine->fresh('catalogItem');
        });
    }

    /**
     * The supplier's own reference, when it is free — the pharmacist
     * recognises it on the delivery note. Two suppliers can use the same
     * reference for different products, so a taken code is suffixed rather
     * than reused, which would merge two distinct products into one.
     */
    private function code(SupplierCatalogItem $item): string
    {
        $base = Str::of((string) $item->reference)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->toString();

        if ($base === '') {
            $base = 'MED-'.Str::upper(Str::random(6));
        }

        $base = mb_substr($base, 0, 40);
        $code = $base;
        $suffix = 1;

        while (CatalogItem::withTrashed()->where('code', $code)->exists()) {
            $code = mb_substr($base, 0, 36).'-'.(++$suffix);
        }

        return $code;
    }
}
