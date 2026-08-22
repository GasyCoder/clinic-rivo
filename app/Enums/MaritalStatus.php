<?php

namespace App\Enums;

enum MaritalStatus: string
{
    case Single = 'SINGLE';
    case Married = 'MARRIED';
    case Divorced = 'DIVORCED';
    case Widowed = 'WIDOWED';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Célibataire',
            self::Married => 'Marié(e)',
            self::Divorced => 'Divorcé(e)',
            self::Widowed => 'Veuf / Veuve',
        };
    }
}
