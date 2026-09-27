<?php

namespace App\Enums;

enum ConsultationEvolution: string
{
    case Improving = 'IMPROVING';
    case Stable = 'STABLE';
    case Worsening = 'WORSENING';
    case Fluctuating = 'FLUCTUATING';
    case Unspecified = 'UNSPECIFIED';

    public function label(): string
    {
        return match ($this) {
            self::Improving => 'Amélioration',
            self::Stable => 'Stable',
            self::Worsening => 'Aggravation',
            self::Fluctuating => 'Fluctuante',
            self::Unspecified => 'Non précisé',
        };
    }
}
