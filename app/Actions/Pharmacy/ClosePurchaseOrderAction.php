<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-179 — solder d'un geste une commande dont on n'attend plus rien.
 *
 * Abandonner ligne par ligne trace précisément ce qui a manqué ; cette action
 * fait la même chose en une fois, avec un motif commun, pour une commande que
 * le fournisseur n'honorera plus.
 *
 * Ce n'est **pas** une annulation : la commande a réellement été envoyée,
 * souvent livrée en partie, et peut porter une facture — l'annuler effacerait
 * cet engagement. Le droit est donc celui de l'achat (`purchase_orders.cancel`)
 * et non celui de la réception : renoncer à ce qui reste dû est une décision
 * d'acheteur, là où constater une rupture ligne à ligne est un constat de
 * réception.
 */
class ClosePurchaseOrderAction
{
    public function execute(PurchaseOrder $order, string $reason, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.cancel')) {
            throw new AuthorizationException('Vous ne pouvez pas clôturer cette commande.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Le motif de la clôture est obligatoire.',
            ]);
        }

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Seule une commande envoyée au fournisseur et encore attendue peut être clôturée.',
                ]);
            }

            $outstanding = $order->lines()->lockForUpdate()->get()
                ->reject(fn (PurchaseOrderLine $line) => $line->isSettled());

            if ($outstanding->isEmpty()) {
                throw ValidationException::withMessages([
                    'reason' => 'Cette commande n’attend plus rien : il n’y a aucun reliquat à abandonner.',
                ]);
            }

            $outstanding->each(fn (PurchaseOrderLine $line) => $line->update([
                'shortage_at' => now(),
                'shortage_reason' => $reason,
                'shortage_by' => $actor->localUserId(),
                ...$actor->externalAttribution('shortage'),
            ]));

            $order->update([
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('updated'),
            ]);

            $order->refreshReceptionStatus();

            return $order->fresh('lines');
        });
    }
}
