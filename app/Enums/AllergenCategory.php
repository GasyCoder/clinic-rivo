<?php

namespace App\Enums;

enum AllergenCategory: string
{
    case Medication = 'MEDICATION';
    case Food = 'FOOD';
    case Material = 'MATERIAL';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Medication => 'Médicaments',
            self::Food => 'Aliments',
            self::Material => 'Matériaux',
            self::Other => 'Autres',
        };
    }
}
