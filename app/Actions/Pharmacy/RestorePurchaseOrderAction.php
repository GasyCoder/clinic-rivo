<?php

namespace App\Actions\Pharmacy;

use App\Models\PurchaseOrder;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class RestorePurchaseOrderAction
{
    public function execute(PurchaseOrder $order, CatalogActor $actor): PurchaseOrder
    {
        if ($actor->cannot('purchase_orders.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer cette commande.');
        }

        $order->forceFill([
            'updated_by' => $actor->localUserId(),
            ...$actor->externalAttribution('updated'),
        ]);
        $order->restore();

        return $order;
    }
}
