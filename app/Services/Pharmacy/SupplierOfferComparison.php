<?php

namespace App\Services\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Support\Money;
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

        $medicines = $offers
            ->groupBy('medicine_id')
            ->map(function (Collection $group) use ($selected, $today): array {
                /** @var Medicine $medicine */
                $medicine = $group->first()->medicine;

                $quotes = $group
                    ->map(fn (MedicineSupplierOffer $offer): array => [
                        'supplier_uuid' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->uuid,
                        'supplier_name' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->name,
                        'price' => Money::normalize((string) $offer->quoted_price),
                        'effective_from' => $offer->effective_from?->toDateString(),
                    ])
                    ->sortBy(fn (array $quote) => (float) $quote['price'])
                    ->values();

                return [
                    'medicine_uuid' => $medicine->uuid,
                    'code' => $medicine->catalogItem?->code,
                    'name' => $medicine->catalogItem?->name,
                    'unit' => $medicine->catalogItem?->unit,
                    'in_stock' => $medicine->lots
                        ->filter(fn (MedicineLot $lot) => $lot->isUsableOn($today))
                        ->sum(fn (MedicineLot $lot) => $lot->availableQuantity()),
                    'minimum_stock' => $medicine->minimum_stock,
                    // Cheapest first, so the comparison reads itself; the
                    // choice stays the buyer's — a cheaper supplier may be
                    // out of stock or slower, and the screen never decides.
                    'best_price' => $quotes->first()['price'] ?? null,
                    'quotes' => $quotes->all(),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'suppliers' => $suppliers->map(fn (MedicineSupplier $supplier): array => [
                'uuid' => $supplier->uuid,
                'name' => $supplier->name,
                'code' => $supplier->code,
                'offers_count' => $offers->where('medicine_supplier_id', $supplier->id)->count(),
            ])->all(),
            'medicines' => $medicines,
        ];
    }
}
