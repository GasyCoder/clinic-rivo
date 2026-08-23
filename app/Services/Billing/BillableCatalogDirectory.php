<?php

namespace App\Services\Billing;

use App\Enums\CatalogItemType;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Patient;
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
    public function __construct(private readonly CatalogTariffResolver $tariffs) {}

    /** @return Collection<int, array<string, mixed>> */
    public function services(Patient $patient): Collection
    {
        $category = $this->tariffs->categoryFor($patient);
        $relationship = $this->tariffs->relationshipFor($patient);

        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('billable', true)
            ->where('reception_selectable', true)
            ->whereNotNull('reception_routing_mode')
            ->with([$relationship => fn ($query) => $query->select([
                'id', 'catalog_item_id', 'tariff_category', 'amount', 'currency',
            ])])
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->map(function (CatalogItem $item) use ($category, $relationship): array {
                /** @var CatalogTariff|null $tariff */
                $tariff = $item->getRelation($relationship);

                return [
                    'uuid' => $item->uuid,
                    'code' => $item->code,
                    'name' => $item->name,
                    'module' => $item->module->value,
                    'module_label' => $item->module->label(),
                    'routing_mode' => $item->reception_routing_mode->value,
                    'routing_label' => $item->reception_routing_mode->label(),
                    'unit' => $item->unit,
                    'tariff_category' => $category->value,
                    'tariff_category_label' => $category->label(),
                    'tariff_available' => $tariff !== null,
                    'tariff_amount' => $tariff?->amount,
                    'currency' => $tariff?->currency ?? 'MGA',
                ];
            })
            ->values();
    }
}
