<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 — un brouillon jamais envoyé peut partir à la corbeille, avec son
 * motif ; il se restaure depuis la Corbeille (ADR-061). Une commande envoyée
 * a engagé la clinique auprès d'un fournisseur : elle s'annule, elle ne se
 * jette pas.
 */
class TrashPurchaseOrderAction
{
    public function execute(PurchaseOrder $order, string $reason, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas mettre cette commande à la corbeille.');
        }

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Seul un brouillon peut être mis à la corbeille. Une commande envoyée au fournisseur s’annule.',
                ]);
            }

            $order->forceFill([
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('updated'),
            ])->save();
            $order->delete_reason = trim($reason);
            $order->delete();

            return $order;
        });
    }
}
