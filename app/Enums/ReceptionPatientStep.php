<?php

namespace App\Enums;

enum ReceptionPatientStep: string
{
    case Type = 'type';
    case Identity = 'identite';
    case Contact = 'contact';
    case Coverage = 'couverture';
    case Confirmation = 'confirmation';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
