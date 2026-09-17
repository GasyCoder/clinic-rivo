<?php

namespace App\Actions\Pharmacy;

use App\Actions\Pharmacy\Concerns\ResolvesOrderedMedicine;
use App\Enums\PurchaseOrderStatus;
use App\Models\MedicineSupplier;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-097 — spec §8: a supplier purchase order in DRAFT status. Each
 * line's unit_price is a snapshot taken now (the current
 * MedicineSupplierOffer if one exists, or an explicit override) — it is
 * never recomputed later even if the supplier's quoted price changes
 * before the order is received.
 */
class CreatePurchaseOrderAction
{
    use ResolvesOrderedMedicine;

    public function __construct(private readonly FinancialNumberGenerator $numbers) {}

    /**
     * @param array{
     *   expected_delivery_at?: ?string,
     *   notes?: ?string,
     *   lines: array<int, array{medicine_uuid?: ?string, supplier_catalog_item_uuid?: ?string, quantity_ordered: int, unit_price: string}>
     * } $data
     */
    public function execute(MedicineSupplier $supplier, array $data, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.create')) {
            throw new AuthorizationException('Vous ne pouvez pas créer de commande fournisseur.');
        }

        return DB::transaction(function () use ($supplier, $data, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                'order_number' => $this->numbers->purchaseOrder(),
                'medicine_supplier_id' => $supplier->getKey(),
                'status' => PurchaseOrderStatus::Draft,
                'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
                'total_amount' => 0,
                'currency' => 'MGA',
                'notes' => $data['notes'] ?? null,
                // ADR-098 — a portal order has no local author; its portal
                // identity is kept instead.
                'created_by' => $actor->localUserId(),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
                ...$actor->externalAttribution('updated'),
            ]);

            $totalMinor = $this->createLines($order, $supplier, $data['lines'], $actor);

            $order->update(['total_amount' => Money::fromMinor($totalMinor)]);

            return $order->fresh('lines');
        });
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function createLines(PurchaseOrder $order, MedicineSupplier $supplier, array $lines, CatalogActor $actor): int
    {
        $totalMinor = 0;

        foreach ($lines as $line) {
            $medicine = $this->resolveMedicine($line, $supplier, $actor);
            $offer = $medicine->currentOfferFor($supplier)->first();
            $quantity = (int) $line['quantity_ordered'];
            $unitPriceMinor = Money::toMinor($line['unit_price']);
            $lineTotalMinor = Money::multiply($quantity, $line['unit_price']);

            $order->lines()->create([
                'medicine_id' => $medicine->getKey(),
                'medicine_supplier_offer_id' => $offer?->getKey(),
                'quantity_ordered' => $quantity,
                'unit_price' => Money::fromMinor($unitPriceMinor),
                'line_total' => Money::fromMinor($lineTotalMinor),
                'quantity_received' => 0,
            ]);

            $totalMinor += $lineTotalMinor;
        }

        return $totalMinor;
    }
}
