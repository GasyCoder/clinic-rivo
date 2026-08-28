<?php

namespace App\Services\Billing;

use App\Enums\CatalogItemType;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Support\Money;
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
    public function services(Episode $episode): Collection
    {
        $category = $this->tariffs->categoryFor($episode, required: false);
        $relationship = $this->tariffs->relationshipFor($episode);
        $coverage = $this->tariffs->coverageSnapshot($episode, required: false);

        $query = CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('billable', true)
            ->where('reception_selectable', true)
            ->whereNotNull('reception_routing_mode')
            ->orderBy('module')
            ->orderBy('name');

        if ($relationship) {
            $query->with([$relationship => fn ($tariffQuery) => $tariffQuery->select([
                'id', 'catalog_item_id', 'tariff_category', 'amount', 'currency',
            ])]);
        }

        return $query->get()
            ->map(function (CatalogItem $item) use ($category, $relationship, $coverage): array {
                /** @var CatalogTariff|null $tariff */
                $tariff = $relationship ? $item->getRelation($relationship) : null;
                $grossMinor = $tariff ? Money::toMinor($tariff->amount) : null;
                $coverageMinor = $grossMinor !== null && $coverage['coverage_rate'] !== null
                    ? Money::percentage($grossMinor, $coverage['coverage_rate'])
                    : null;

                return [
                    'uuid' => $item->uuid,
                    'code' => $item->code,
                    'name' => $item->name,
                    'module' => $item->module->value,
                    'module_label' => $item->module->label(),
                    'routing_mode' => $item->reception_routing_mode->value,
                    'routing_label' => $item->reception_routing_mode->label(),
                    'unit' => $item->unit,
                    'tariff_category' => $category?->value,
                    'tariff_category_label' => $category?->label() ?? 'À régulariser',
                    'tariff_available' => $tariff !== null,
                    'tariff_amount' => $tariff?->amount,
                    'coverage_rate' => $coverage['coverage_rate'],
                    'coverage_amount' => $coverageMinor !== null ? Money::fromMinor($coverageMinor) : null,
                    'patient_amount' => $grossMinor !== null && $coverageMinor !== null
                        ? Money::fromMinor($grossMinor - $coverageMinor)
                        : null,
                    'currency' => $tariff?->currency ?? 'MGA',
                ];
            })
            ->values();
    }
}
