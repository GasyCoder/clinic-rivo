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

    /**
     * Les demandes que la Pharmacie a encore devant elle : ni délivrées, ni
     * annulées. Une seule définition pour sa file et pour « ce patient a-t-il
     * encore besoin de la Pharmacie ? » (ADR-119) — deux listes recopiées
     * finiraient par ne plus compter les mêmes demandes.
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [
            self::AwaitingInvoice->value,
            self::AwaitingPayment->value,
            self::Ready->value,
            self::PartiallyDispensed->value,
        ];
    }
}
