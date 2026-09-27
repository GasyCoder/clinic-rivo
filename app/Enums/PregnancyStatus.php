<?php

namespace App\Enums;

enum PregnancyStatus: string
{
    case Ongoing = 'ONGOING';
    case Delivered = 'DELIVERED';
    case Ended = 'ENDED';

    public function label(): string
    {
        return match ($this) {
            self::Ongoing => 'Grossesse en cours',
            self::Delivered => 'Accouchement enregistré',
            self::Ended => 'Grossesse terminée',
        };
    }
}
