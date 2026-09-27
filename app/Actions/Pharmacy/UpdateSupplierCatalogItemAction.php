<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098 — correcting a catalogue line, because an import transcribes a
 * supplier's document and a document can be misread: a shifted column, a
 * price in the wrong unit, a label cut short.
 *
 * A line is only a transcription: correcting it never rewrites what the
 * clinic has already decided. The medicine created from it keeps its own
 * name and code (they were snapshotted at creation), and the purchase price
 * already recorded keeps its version — a new price is a new version, set by
 * SetMedicineSupplierOfferAction, never a silent mutation of the old one.
 *
 * The permission is the catalogue's own: whoever may correct the file may
 * correct its lines. No new permission is created for this.
 */
class UpdateSupplierCatalogItemAction
{
    /** @param array{reference: string, medicine_label: string, presentation?: ?string, family_label?: ?string, supplier_price?: ?string} $data */
    public function execute(SupplierCatalogItem $item, array $data, CatalogActor $actor): SupplierCatalogItem
    {
        if ($actor->cannot('supplier_catalogs.update')) {
            throw new AuthorizationException('Vous ne pouvez pas corriger une ligne de catalogue.');
        }

        return DB::transaction(function () use ($item, $data): SupplierCatalogItem {
            $item = SupplierCatalogItem::query()->lockForUpdate()->findOrFail($item->id);

            $item->update([
                'reference' => trim($data['reference']),
                'medicine_label' => trim($data['medicine_label']),
                'presentation' => filled($data['presentation'] ?? null) ? trim($data['presentation']) : null,
                'family_label' => filled($data['family_label'] ?? null) ? trim($data['family_label']) : null,
                'supplier_price' => filled($data['supplier_price'] ?? null) ? $data['supplier_price'] : null,
            ]);

            return $item->fresh();
        });
    }
}
