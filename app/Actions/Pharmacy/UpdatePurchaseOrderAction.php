<?php

namespace App\Actions\Pharmacy;

use App\Actions\Pharmacy\Concerns\ResolvesOrderedMedicine;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrderAction
{
    use ResolvesOrderedMedicine;

    /** @param array{expected_delivery_at?: ?string, notes?: ?string, lines: array<int, array<string, mixed>>} $data */
    public function execute(PurchaseOrder $order, array $data, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier cette commande.');
        }

        return DB::transaction(function () use ($order, $data, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une commande en brouillon peut être modifiée.',
                ]);
            }

            $order->lines()->delete();

            $totalMinor = 0;

            foreach ($data['lines'] as $line) {
                $medicine = $this->resolveMedicine($line, $order->supplier, $actor);
                $offer = $medicine->currentOfferFor($order->supplier)->first();
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

            $order->update([
                'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total_amount' => Money::fromMinor($totalMinor),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('updated'),
            ]);

            return $order->fresh('lines');
        });
    }
}
