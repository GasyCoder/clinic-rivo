<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-098/ADR-009 — withdrawing a catalogue line the supplier no longer
 * proposes, or that the import invented from a stray row. Soft Delete with
 * a reason: the line leaves the screens and comes back if it was a mistake.
 *
 * Nothing is destroyed. A purchase price already created from this line
 * keeps pointing at it, and the medicine it produced is untouched — what is
 * withdrawn is the supplier's offer of it, not the clinic's product.
 */
class ArchiveSupplierCatalogItemAction
{
    public function execute(SupplierCatalogItem $item, string $reason, CatalogActor $actor): void
    {
        if ($actor->cannot('supplier_catalogs.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas retirer une ligne de catalogue.');
        }

        DB::transaction(function () use ($item, $reason, $actor): void {
            $item = SupplierCatalogItem::query()->lockForUpdate()->findOrFail($item->id);

            $item->delete_reason = trim($reason);
            $item->deleted_by = $actor->localUserId();
            $item->delete();
        });
    }
}
