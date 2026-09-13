<?php

namespace App\Enums;

/**
 * The patient's overall state as the doctor judges it on sight.
 *
 * Nullable wherever it is stored: no value is ever pre-selected, because
 * "Bon" pre-checked would be an assessment the software made, not the
 * doctor.
 */
enum GeneralCondition: string
{
    case Good = 'GOOD';
    case Fair = 'FAIR';
    case Altered = 'ALTERED';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Bon',
            self::Fair => 'Moyen',
            self::Altered => 'Altéré',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
