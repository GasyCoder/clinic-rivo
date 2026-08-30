<?php

namespace App\Enums;

enum PharmacyDispenseType: string
{
    case Internal = 'INTERNAL';
    case External = 'EXTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Patient interne',
            self::External => 'Client externe',
        };
    }
}
