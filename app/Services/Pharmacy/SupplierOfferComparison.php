<?php

namespace App\Services\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Support\Money;
use App\Support\ProductLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What each supplier currently quotes for the same medicine, side by side.
 *
 * Preparing an order meant opening one supplier folder after another to
 * compare prices. Nothing new is stored: this reads the versioned offers
 * (`medicine_supplier_offers`, ADR-097) that already carry every price, and
 * the stock the clinic still holds — so « faut-il commander ? » and « chez
 * qui ? » are answered on the same line.
 */
class SupplierOfferComparison
{
    /**
     * @param  array<int, string>  $supplierUuids  restrict to these suppliers; all active ones when empty
     * @return array<string, mixed>
     */
    public function forSite(array $supplierUuids = []): array
    {
        // An archived supplier is soft-deleted, so the default scope already
        // keeps it out: nothing is ordered from a folder that was closed.
        $suppliers = MedicineSupplier::query()
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'code']);

        $selected = $supplierUuids === []
            ? $suppliers
            : $suppliers->whereIn('uuid', $supplierUuids)->values();

        $offers = MedicineSupplierOffer::query()
            ->where('active_key', 'CURRENT')
            ->whereIn('medicine_supplier_id', $selected->pluck('id'))
            ->with(['medicine.catalogItem:id,code,name,unit', 'medicine.lots' => fn ($query) => $query->withReservedQuantity()])
            ->get();

        $today = CarbonImmutable::today();

        $rows = $offers
            ->groupBy('medicine_id')
            ->map(function (Collection $group) use ($selected, $today): array {
                /** @var Medicine $medicine */
                $medicine = $group->first()->medicine;

                return [
                    'key' => 'medicine:'.$medicine->uuid,
                    'medicine_uuid' => $medicine->uuid,
                    'code' => $medicine->catalogItem?->code,
                    'name' => $medicine->catalogItem?->name,
                    'unit' => $medicine->catalogItem?->unit,
                    'in_clinic_catalog' => true,
                    'product_group' => ProductLabel::normalize($medicine->catalogItem?->name),
                    'in_stock' => $medicine->lots
                        ->filter(fn (MedicineLot $lot) => $lot->isUsableOn($today))
                        ->sum(fn (MedicineLot $lot) => $lot->availableQuantity()),
                    'minimum_stock' => $medicine->minimum_stock,
                    'quotes' => $group->map(fn (MedicineSupplierOffer $offer): array => [
                        'key' => 'offer:'.$offer->id,
                        'supplier_uuid' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->uuid,
                        'supplier_name' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->name,
                        'supplier_catalog_item_uuid' => null,
                        'reference' => null,
                        'price' => Money::normalize((string) $offer->quoted_price),
                        'effective_from' => $offer->effective_from?->toDateString(),
                    ])->values()->all(),
                ];
            })
            ->keyBy('product_group');

        // The supplier's active catalogue lines the clinic has not taken up
        // yet. Without them, a supplier whose catalogue is imported but not
        // yet linked offered nothing here, although the order form of its
        // own folder lists every line. Same product name, same row: the
        // order resolves them to one medicine (ADR-098), so they compare.
        foreach ($this->unlinkedCatalogLines($selected) as [$supplier, $item]) {
            $group = ProductLabel::normalize($item->medicine_label);
            $row = $rows->get($group) ?? [
                'key' => 'catalog:'.$group,
                'medicine_uuid' => null,
                'code' => $item->reference,
                'name' => $item->medicine_label,
                'unit' => $item->presentation,
                'in_clinic_catalog' => false,
                'product_group' => $group,
                'in_stock' => null,
                'minimum_stock' => null,
                'quotes' => [],
            ];

            $row['quotes'][] = [
                'key' => 'catalog-item:'.$item->uuid,
                'supplier_uuid' => $supplier->uuid,
                'supplier_name' => $supplier->name,
                'supplier_catalog_item_uuid' => $item->uuid,
                'reference' => $item->reference,
                'price' => filled($item->supplier_price) ? Money::normalize((string) $item->supplier_price) : null,
                'effective_from' => null,
            ];

            $rows->put($group, $row);
        }

        $medicines = $rows
            ->map(function (array $row): array {
                // Cheapest first, so the comparison reads itself; the
                // choice stays the buyer's — a cheaper supplier may be out
                // of stock or slower, and the screen never decides. A line
                // without a price comes last: it cannot be compared.
                $quotes = collect($row['quotes'])
                    ->sortBy(fn (array $quote) => $quote['price'] === null ? PHP_FLOAT_MAX : (float) $quote['price'])
                    ->values();

                return [
                    ...$row,
                    'best_price' => $quotes->first(fn (array $quote) => $quote['price'] !== null)['price'] ?? null,
                    'quotes' => $quotes->all(),
                ];
            })
            ->sortBy(fn (array $row) => mb_strtolower((string) $row['name']))
            ->values()
            ->all();

        $catalogCounts = collect($this->unlinkedCatalogLines($selected))
            ->countBy(fn (array $pair) => $pair[0]->id);

        return [
            'suppliers' => $suppliers->map(fn (MedicineSupplier $supplier): array => [
                'uuid' => $supplier->uuid,
                'name' => $supplier->name,
                'code' => $supplier->code,
                'offers_count' => $offers->where('medicine_supplier_id', $supplier->id)->count()
                    + ($catalogCounts[$supplier->id] ?? 0),
            ])->all(),
            'medicines' => $medicines,
        ];
    }

    /** @var array<int, array{0: MedicineSupplier, 1: SupplierCatalogItem}>|null */
    private ?array $unlinked = null;

    /**
     * @param  Collection<int, MedicineSupplier>  $suppliers
     * @return array<int, array{0: MedicineSupplier, 1: SupplierCatalogItem}>
     */
    private function unlinkedCatalogLines(Collection $suppliers): array
    {
        return $this->unlinked ??= SupplierCatalogItem::query()
            ->whereNull('linked_medicine_id')
            ->whereHas('catalog', fn ($query) => $query
                ->where('active_key', 'ACTIVE')
                ->whereIn('medicine_supplier_id', $suppliers->pluck('id')))
            ->with('catalog:id,medicine_supplier_id')
            ->orderBy('row_number')
            ->get()
            ->map(fn (SupplierCatalogItem $item) => [$suppliers->firstWhere('id', $item->catalog->medicine_supplier_id), $item])
            ->all();
    }
}
