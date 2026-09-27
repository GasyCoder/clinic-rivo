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
 * ADR-179 — un article commandé que le fournisseur ne livrera pas.
 *
 * Constater une rupture est un **geste de réception** : c'est la personne qui
 * a la livraison sous les yeux qui voit ce qui manque (même raisonnement que
 * l'ADR-176 pour la réception elle-même). Le droit est donc
 * `goods_receipts.create`, pas un droit d'achat.
 *
 * Rien n'est effacé : la ligne garde sa quantité commandée, son prix et ce
 * qu'elle a déjà reçu. Seul son reliquat cesse d'être attendu — ce qui permet
 * enfin à la commande de se clore.
 */
class MarkPurchaseOrderLineShortageAction
{
    public function execute(PurchaseOrderLine $line, string $reason, CatalogActor $actor): PurchaseOrderLine
    {
        if ($actor->cannot('goods_receipts.create')) {
            throw new AuthorizationException('Vous ne pouvez pas signaler une rupture sur cette commande.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Le motif de la rupture est obligatoire.',
            ]);
        }

        return DB::transaction(function () use ($line, $reason, $actor): PurchaseOrderLine {
            $line = PurchaseOrderLine::query()->with('medicine.catalogItem:id,name')->lockForUpdate()->findOrFail($line->id);
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($line->purchase_order_id);
            $name = $line->medicine->catalogItem?->name ?? 'Ce produit';

            $this->guardReceivable($order);

            if ($line->isShort()) {
                throw ValidationException::withMessages([
                    'reason' => "{$name} est déjà signalé en rupture sur cette commande.",
                ]);
            }

            if ($line->quantity_received >= $line->quantity_ordered) {
                throw ValidationException::withMessages([
                    'reason' => "{$name} a été livré en entier : il n'y a rien à abandonner.",
                ]);
            }

            $line->update([
                'shortage_at' => now(),
                'shortage_reason' => $reason,
                'shortage_by' => $actor->localUserId(),
                ...$actor->externalAttribution('shortage'),
            ]);

            $order->refreshReceptionStatus();

            return $line;
        });
    }

    /**
     * Le fournisseur livre finalement, ou la rupture a été signalée par
     * erreur : la ligne redevient attendue et la commande se rouvre.
     */
    public function revert(PurchaseOrderLine $line, CatalogActor $actor): PurchaseOrderLine
    {
        if ($actor->cannot('goods_receipts.create')) {
            throw new AuthorizationException('Vous ne pouvez pas revenir sur une rupture.');
        }

        return DB::transaction(function () use ($line): PurchaseOrderLine {
            $line = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->id);
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($line->purchase_order_id);

            if (! $line->isShort()) {
                throw ValidationException::withMessages([
                    'shortage_at' => 'Cette ligne n’est pas signalée en rupture.',
                ]);
            }

            if ($order->status === PurchaseOrderStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'Cette commande est annulée.',
                ]);
            }

            $line->update([
                'shortage_at' => null,
                'shortage_reason' => null,
                'shortage_by' => null,
                'external_shortage_by_uuid' => null,
                'external_shortage_by_name' => null,
            ]);

            $order->refreshReceptionStatus();

            return $line;
        });
    }

    private function guardReceivable(PurchaseOrder $order): void
    {
        if (! in_array($order->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
            throw ValidationException::withMessages([
                'status' => 'Seule une commande envoyée au fournisseur et encore attendue peut porter une rupture.',
            ]);
        }
    }
}
