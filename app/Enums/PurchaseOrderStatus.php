<?php

namespace App\Enums;

/**
 * ADR-097 — spec §8. PartiallyReceived and Received are both derived from
 * aggregate quantity_received vs quantity_ordered across a purchase
 * order's lines (see ReceiveGoodsAction), never set directly by a user.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Ordered = 'ORDERED';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Ordered => 'Commandée',
            self::PartiallyReceived => 'Partiellement reçue',
            self::Received => 'Reçue',
            self::Cancelled => 'Annulée',
        };
    }
}
