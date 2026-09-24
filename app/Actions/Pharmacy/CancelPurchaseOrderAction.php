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

            // ADR-179 — une commande CLÔTURÉE a réellement été envoyée, souvent
            // livrée en partie, et peut porter une facture : l'annuler dirait
            // qu'elle n'a jamais eu lieu, et la rendrait au passage jetable
            // (ADR-176 : une commande annulée part à la corbeille). Pour
            // revenir dessus, on retire d'abord ses ruptures — elle redevient
            // attendue — puis on l'annule.
            if ($order->status->isClosedOut()) {
                throw ValidationException::withMessages([
                    'status' => match ($order->status) {
                        PurchaseOrderStatus::Cancelled => 'Cette commande est déjà annulée.',
                        PurchaseOrderStatus::Closed => 'Cette commande est clôturée : elle a été envoyée, et souvent livrée en partie. Retirez ses ruptures pour la rouvrir avant de l’annuler.',
                        default => 'Une commande déjà reçue ne peut plus être annulée.',
                    },
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
