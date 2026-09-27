<?php

namespace App\Enums;

enum PregnancyDatingMethod: string
{
    case LastMenstrualPeriod = 'LMP';
    case Ultrasound = 'ULTRASOUND';
    case ManualCorrection = 'MANUAL_CORRECTION';

    public function label(): string
    {
        return match ($this) {
            self::LastMenstrualPeriod => 'Dernières règles',
            self::Ultrasound => 'Échographie',
            self::ManualCorrection => 'Correction manuelle',
        };
    }
}
