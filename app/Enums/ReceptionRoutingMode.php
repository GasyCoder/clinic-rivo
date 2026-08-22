<?php

namespace App\Enums;

/**
 * Default clinical path for a priced service selected at Reception.
 *
 * This is deliberately independent from CatalogModule: two services owned
 * by Medicine (for example a general consultation and an ultrasound) may
 * have different entry paths.
 */
enum ReceptionRoutingMode: string
{
    case MedicineDirect = 'MEDICINE_DIRECT';
    case CareThenMedicine = 'CARE_THEN_MEDICINE';
    case CareOnly = 'CARE_ONLY';

    public function label(): string
    {
        return match ($this) {
            self::MedicineDirect => 'Médecine directement',
            self::CareThenMedicine => 'Soins puis Médecine',
            self::CareOnly => 'Soins uniquement',
        };
    }

    public function startsWithCare(): bool
    {
        return in_array($this, [self::CareThenMedicine, self::CareOnly], true);
    }

    public function requiresMedicine(): bool
    {
        return in_array($this, [self::MedicineDirect, self::CareThenMedicine], true);
    }
}
