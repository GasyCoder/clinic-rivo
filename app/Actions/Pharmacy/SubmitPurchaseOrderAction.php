<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitPurchaseOrderAction
{
    public function execute(PurchaseOrder $order, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.submit')) {
            throw new AuthorizationException('Vous ne pouvez pas passer cette commande.');
        }

        return DB::transaction(function () use ($order, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une commande en brouillon peut être passée.',
                ]);
            }

            if (! $order->lines()->exists()) {
                throw ValidationException::withMessages([
                    'lines' => 'Une commande sans produit ne peut pas être passée.',
                ]);
            }

            $order->update([
                'status' => PurchaseOrderStatus::Ordered,
                'ordered_at' => now(),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('updated'),
            ]);

            return $order;
        });
    }
}
