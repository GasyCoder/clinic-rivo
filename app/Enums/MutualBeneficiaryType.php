<?php

namespace App\Enums;

enum MutualBeneficiaryType: string
{
    case Principal = 'PRINCIPAL';
    case FamilyMember = 'FAMILY_MEMBER';

    public function label(): string
    {
        return match ($this) {
            self::Principal => 'Principal',
            self::FamilyMember => 'Membre de la famille',
        };
    }
}
