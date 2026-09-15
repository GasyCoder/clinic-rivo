<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPurchaseOrderAction
{
    public function execute(PurchaseOrder $order, string $reason, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.cancel')) {
            throw new AuthorizationException('Vous ne pouvez pas annuler cette commande.');
        }

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === PurchaseOrderStatus::Received || $order->status === PurchaseOrderStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'Une commande déjà reçue ou annulée ne peut pas être annulée à nouveau.',
                ]);
            }

            $order->update([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->localUserId(),
                'cancellation_reason' => trim($reason),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('cancelled'),
                ...$actor->externalAttribution('updated'),
            ]);

            return $order;
        });
    }
}
