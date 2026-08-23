<?php

namespace App\Enums;

enum PharmacyStockMovementType: string
{
    case Opening = 'OPENING';
    case Entry = 'ENTRY';
    case Dispensing = 'DISPENSING';
    case Return = 'RETURN';
    case Adjustment = 'ADJUSTMENT';
    case TransferIn = 'TRANSFER_IN';
    case TransferOut = 'TRANSFER_OUT';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Stock initial',
            self::Entry => 'Entrée',
            self::Dispensing => 'Délivrance',
            self::Return => 'Retour',
            self::Adjustment => 'Ajustement',
            self::TransferIn => 'Transfert entrant',
            self::TransferOut => 'Transfert sortant',
        };
    }
}
