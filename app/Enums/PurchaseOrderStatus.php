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
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Ordered => 'Commandée',
            self::PartiallyReceived => 'Partiellement reçue',
            self::Received => 'Reçue',
            self::Closed => 'Clôturée',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * ADR-179 — « Clôturée » n'est pas « Reçue ». Une commande dont un article
     * est resté en rupture n'a pas été livrée en entier, et l'écrire « Reçue »
     * mentirait à qui relira l'historique d'achat. Elle n'est pas « Annulée »
     * non plus : elle a réellement été envoyée, souvent livrée en partie, et
     * peut porter une facture. Plus rien n'y est attendu, voilà tout.
     */
    public function isClosedOut(): bool
    {
        return in_array($this, [self::Received, self::Closed, self::Cancelled], true);
    }
}
