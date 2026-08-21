<?php

namespace App\Services\Billing;

use App\Enums\CatalogItemType;
use App\Models\CatalogItem;
use Illuminate\Support\Collection;

/**
 * Read model for operational service selection.
 *
 * Reception may select priced services (consultation, ECG, ultrasound,
 * analysis, etc.) but must not directly sell medicine, consumables or
 * equipment. Prices remain server-owned snapshots when invoiced.
 */
class BillableCatalogDirectory
{
    /** @return Collection<int, array<string, mixed>> */
    public function services(): Collection
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('billable', true)
            ->whereHas('currentTariff')
            ->with('currentTariff:id,catalog_item_id,amount,currency')
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $item) => [
                'uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'module' => $item->module->value,
                'module_label' => $item->module->label(),
                'unit' => $item->unit,
                'tariff_amount' => $item->currentTariff->amount,
                'currency' => $item->currentTariff->currency,
            ])
            ->values();
    }
}
