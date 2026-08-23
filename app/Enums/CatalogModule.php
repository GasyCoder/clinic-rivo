<?php

namespace App\Enums;

enum CatalogModule: string
{
    case Reception = 'RECEPTION';
    case Medicine = 'MEDICINE';
    case Care = 'CARE';
    case Laboratory = 'LABORATORY';
    case Pharmacy = 'PHARMACY';
    case Surgery = 'SURGERY';
    case Administration = 'ADMINISTRATION';

    public function label(): string
    {
        return match ($this) {
            self::Reception => 'Réception',
            self::Medicine => 'Médecine',
            self::Care => 'Soins',
            self::Laboratory => 'Laboratoire',
            self::Pharmacy => 'Pharmacie',
            self::Surgery => 'Chirurgie',
            self::Administration => 'Administration',
        };
    }
}
