<?php

namespace App\Enums;

/**
 * ADR-161 — le niveau de soins d'un emplacement. La clinique dispose d'une
 * surveillance continue et d'une réanimation internes (confirmé par le
 * propriétaire) : une aggravation s'y traite par une mutation, et le séjour
 * continue. Les noms des services restent du texte libre.
 */
enum HospitalCareLevel: string
{
    case Standard = 'STANDARD';
    case Continuous = 'CONTINUOUS';
    case Intensive = 'INTENSIVE';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Hospitalisation standard',
            self::Continuous => 'Surveillance continue',
            self::Intensive => 'Réanimation',
        };
    }
}
