<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-175, amendée par l'ADR-176 — partent à la corbeille, avec leur motif et
 * restaurables depuis la Corbeille (ADR-061) :
 *
 *   - un brouillon jamais envoyé, qui n'a engagé personne ;
 *   - une commande ANNULÉE, dont l'engagement est déjà retiré.
 *
 * Une commande vivante — envoyée, partiellement reçue, reçue — ne se jette
 * jamais : elle engage la clinique auprès d'un tiers, et s'annule d'abord.
 * Rien n'est détruit pour autant : la ligne, son motif d'annulation et son
 * historique restent en base, et la suppression définitive reste refusée dès
 * qu'un envoi, une réception ou une facture existe (ADR-175).
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

            if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Seul un brouillon ou une commande annulée peut être mis à la corbeille. Annulez d’abord cette commande.',
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
