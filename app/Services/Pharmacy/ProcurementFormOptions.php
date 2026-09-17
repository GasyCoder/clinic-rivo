<?php

namespace App\Services\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Support\Money;

/**
 * ADR-098 — what the order and invoice forms offer for one supplier. Shared by
 * the clinic screens and the site API read by the portal, for creating as for
 * correcting, so the four forms always list the same things.
 */
class ProcurementFormOptions
{
    /**
     * What this supplier can actually deliver: the medicines it quotes a
     * current price for, or that its folder links to. Ordering from the
     * whole clinic catalogue made the screen list hundreds of products the
     * supplier never proposed. Falls back to the full catalogue only when
     * the supplier has no product yet, so a first order stays possible
     * before any catalogue is imported — the server forbids nothing here
     * (ADR-098), it only stops guessing.
     *
     * @return array<int, array<string, mixed>> Active medicines, this supplier's current price first.
     */
    public function orderMedicines(MedicineSupplier $supplier): array
    {
        $prices = $supplier->offers()->where('active_key', 'CURRENT')->pluck('quoted_price', 'medicine_id');
        $supplied = $prices->keys()
            ->merge($supplier->medicines()->pluck('medicines.id'))
            ->unique();

        return Medicine::query()->where('active', true)
            ->when($supplied->isNotEmpty(), fn ($query) => $query->whereIn('id', $supplied))
            ->with('catalogItem:id,code,name,unit')
            ->get()
            ->map(fn (Medicine $medicine) => [
                'uuid' => $medicine->uuid,
                'code' => $medicine->catalogItem?->code,
                'name' => $medicine->catalogItem?->name,
                'unit' => $medicine->catalogItem?->unit,
                'quoted_price' => isset($prices[$medicine->id]) ? Money::normalize((string) $prices[$medicine->id]) : null,
            ])
            ->sortBy(fn (array $medicine) => [$medicine['quoted_price'] === null ? 1 : 0, $medicine['name']])
            ->values()
            ->all();
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
