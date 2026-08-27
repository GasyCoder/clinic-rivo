<?php

namespace App\Enums;

enum PharmacyDispenseStatus: string
{
    case AwaitingInvoice = 'AWAITING_INVOICE';
    case AwaitingPayment = 'AWAITING_PAYMENT';
    case Ready = 'READY';
    case PartiallyDispensed = 'PARTIALLY_DISPENSED';
    case Dispensed = 'DISPENSED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingInvoice => 'Facturation à préparer',
            self::AwaitingPayment => 'En attente de règlement',
            self::Ready => 'Payée · prête à délivrer',
            self::PartiallyDispensed => 'Délivrée partiellement',
            self::Dispensed => 'Délivrée',
            self::Cancelled => 'Annulée',
        };
    }

    public function canDispense(): bool
    {
        return in_array($this, [self::Ready, self::PartiallyDispensed], true);
    }
}
