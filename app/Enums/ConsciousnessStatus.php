<?php

namespace App\Enums;

/**
 * Level of consciousness at the examination.
 *
 * `Other` exists so the doctor is never forced into "normal" or "altered"
 * when neither fits; it carries its own free-text precision rather than
 * silently collapsing into one of the two.
 */
enum ConsciousnessStatus: string
{
    case Normal = 'NORMAL';
    case Altered = 'ALTERED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normale',
            self::Altered => 'Altérée',
            self::Other => 'Autre',
        };
    }

    public function description(): ?string
    {
        return match ($this) {
            self::Normal => 'Patient conscient et orienté',
            self::Altered => 'Vigilance ou orientation altérée',
            self::Other => null,
        };
    }

    public function requiresDetails(): bool
    {
        return $this === self::Other;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
