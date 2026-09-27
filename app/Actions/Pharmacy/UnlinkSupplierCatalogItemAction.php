<?php

namespace App\Actions\Pharmacy;

use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098 — undoing a catalogue line attached to the wrong medicine.
 *
 * Linking was possible from the first day; unlinking was not, so a mistake
 * stayed forever. The real case: « Alcool bleu 25Litre » attached to
 * « Alcool blanc 25Litre » — two different products, one purchase price
 * recorded against the wrong one.
 *
 * Unlinking is not a deletion (ADR-010). The current price this line had
 * created is **closed** — `effective_until` stamped, `active_key` released —
 * exactly as a price revision closes the previous one. It stays readable:
 * the clinic really did believe that price for that product, and the audit
 * has to keep saying so.
 *
 * The plain « can supply » link is deliberately left alone: the supplier may
 * still supply that medicine through another line.
 */
class UnlinkSupplierCatalogItemAction
{
    public function execute(SupplierCatalogItem $item, CatalogActor $actor): SupplierCatalogItem
    {
        if ($actor->cannot('medicine_supplier_offers.update')) {
            throw new AuthorizationException('Vous ne pouvez pas défaire ce rattachement.');
        }

        return DB::transaction(function () use ($item, $actor): SupplierCatalogItem {
            $item = SupplierCatalogItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $item->linked_medicine_id) {
                return $item;
            }

            $item->loadMissing('catalog');

            MedicineSupplierOffer::query()
                ->where('supplier_catalog_item_id', $item->getKey())
                ->where('medicine_id', $item->linked_medicine_id)
                ->where('active_key', 'CURRENT')
                ->lockForUpdate()
                ->get()
                ->each(fn (MedicineSupplierOffer $offer) => $offer->fill([
                    'effective_until' => now(),
                    'active_key' => null,
                    'ended_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('ended'),
                ])->save());

            $item->update(['linked_medicine_id' => null]);

            return $item->fresh();
        });
    }
}
