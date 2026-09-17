<?php

namespace App\Services\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Models\SupplierCatalogItem;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * ADR-098 — what the order and invoice forms offer for one supplier. Shared by
 * the clinic screens and the site API read by the portal, for creating as for
 * correcting, so the four forms always list the same things.
 */
class ProcurementFormOptions
{
    /**
     * Everything this supplier can actually deliver, in one list:
     *
     *   - the medicines the clinic already holds for it (a current quoted
     *     price, or its folder's plain link);
     *   - every line of its active catalogue that the clinic has not taken
     *     up yet.
     *
     * The second half is what makes a first order possible at all. Ordering
     * from the whole clinic catalogue listed hundreds of products the
     * supplier never proposed; restricting it to linked medicines then left
     * the list empty until someone had added each product by hand, although
     * the supplier's own catalogue named all 119 of them. A catalogue line
     * carries `catalog_item_uuid` instead of `uuid`: the clinic medicine is
     * created when the order is placed (ADR-098), never here — reading a
     * form must not write anything.
     *
     * @return array<int, array<string, mixed>> This supplier's priced products first.
     */
    public function orderMedicines(MedicineSupplier $supplier): array
    {
        $prices = $supplier->offers()->where('active_key', 'CURRENT')->pluck('quoted_price', 'medicine_id');
        $supplied = $prices->keys()
            ->merge($supplier->medicines()->pluck('medicines.id'))
            ->unique();

        $medicines = Medicine::query()->where('active', true)
            ->when($supplied->isNotEmpty(), fn ($query) => $query->whereIn('id', $supplied))
            ->with('catalogItem:id,code,name,unit')
            ->get()
            ->map(fn (Medicine $medicine) => [
                'uuid' => $medicine->uuid,
                'catalog_item_uuid' => null,
                'code' => $medicine->catalogItem?->code,
                'name' => $medicine->catalogItem?->name,
                'unit' => $medicine->catalogItem?->unit,
                'quoted_price' => isset($prices[$medicine->id]) ? Money::normalize((string) $prices[$medicine->id]) : null,
                'in_clinic_catalog' => true,
            ]);

        return $medicines->concat($this->unlinkedCatalogLines($supplier))
            // What the clinic already holds comes first, and among it what
            // already has a price — that one fills itself. A catalogue is
            // read alphabetically: sorting its hundreds of lines by whether
            // the supplier happened to quote a price would scatter it.
            ->sortBy(fn (array $product) => [
                $product['in_clinic_catalog'] ? 0 : 1,
                $product['in_clinic_catalog'] && $product['quoted_price'] === null ? 1 : 0,
                mb_strtolower((string) $product['name']),
            ])
            ->values()
            ->all();
    }

    /**
     * The active catalogue's rows the clinic has not taken up. Only the
     * active one: an old price list is history, not something to order
     * from (ADR-097).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function unlinkedCatalogLines(MedicineSupplier $supplier): Collection
    {
        $catalog = $supplier->catalogs()->where('active_key', 'ACTIVE')->first();

        if (! $catalog) {
            return collect();
        }

        return $catalog->items()
            ->whereNull('linked_medicine_id')
            ->orderBy('row_number')
            ->get()
            ->map(fn (SupplierCatalogItem $item) => [
                'uuid' => null,
                'catalog_item_uuid' => $item->uuid,
                'code' => $item->reference,
                'name' => $item->medicine_label,
                'unit' => $item->presentation,
                'quoted_price' => filled($item->supplier_price) ? Money::normalize((string) $item->supplier_price) : null,
                'in_clinic_catalog' => false,
            ]);
    }

    /** @return array{medicines: array<int, array<string, mixed>>, orders: array<int, array<string, mixed>>} */
    public function invoiceOptions(MedicineSupplier $supplier): array
    {
        return [
            'medicines' => Medicine::query()->where('active', true)
                ->with('catalogItem:id,code,name')
                ->get()
                ->map(fn (Medicine $medicine) => [
                    'uuid' => $medicine->uuid,
                    'code' => $medicine->catalogItem?->code,
                    'name' => $medicine->catalogItem?->name,
                ])
                ->sortBy('name')
                ->values()
                ->all(),
            'orders' => $supplier->purchaseOrders()
                ->where('status', '!=', PurchaseOrderStatus::Cancelled->value)
                ->with(['lines.medicine.catalogItem:id,code,name', 'receipts:id,uuid,receipt_number,purchase_order_id'])
                ->latest('created_at')
                ->limit(100)
                ->get()
                ->map(fn (PurchaseOrder $order) => [
                    'uuid' => $order->uuid,
                    'order_number' => $order->order_number,
                    'status_label' => $order->status->label(),
                    'lines' => $order->lines->map(fn ($line) => [
                        'medicine_uuid' => $line->medicine->uuid,
                        'description' => $line->medicine->catalogItem?->name,
                        'quantity' => $line->quantity_received ?: $line->quantity_ordered,
                        'unit_price' => (string) $line->unit_price,
                    ])->values(),
                    'receipts' => $order->receipts->map(fn ($receipt) => [
                        'uuid' => $receipt->uuid,
                        'receipt_number' => $receipt->receipt_number,
                    ])->values(),
                ])
                ->values()
                ->all(),
        ];
    }
}
