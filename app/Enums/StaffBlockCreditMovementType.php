<?php

namespace App\Enums;

enum StaffBlockCreditMovementType: string
{
    case Allocation = 'ALLOCATION';
    case Consumption = 'CONSUMPTION';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::Allocation => 'Allocation manuelle',
            self::Consumption => 'Consommation Bloc',
            self::Reversal => 'Réversion',
        };
    }
}
