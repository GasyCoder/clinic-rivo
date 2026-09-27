<?php

namespace App\Enums;

/**
 * How a prescribed medicine is given.
 *
 * Clinically decisive and missing until now: 500 mg orally and 500 mg
 * intravenously are not the same prescription, and a line that omits the
 * route leaves the person administering it to guess.
 */
enum AdministrationRoute: string
{
    case Oral = 'ORAL';
    case Intravenous = 'IV';
    case Intramuscular = 'IM';
    case Subcutaneous = 'SC';
    case Rectal = 'RECTAL';
    case Vaginal = 'VAGINAL';
    case Topical = 'TOPICAL';
    case Ophthalmic = 'OPHTHALMIC';
    case Nasal = 'NASAL';
    case Inhaled = 'INHALED';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Oral => 'Orale',
            self::Intravenous => 'Intraveineuse (IV)',
            self::Intramuscular => 'Intramusculaire (IM)',
            self::Subcutaneous => 'Sous-cutanée (SC)',
            self::Rectal => 'Rectale',
            self::Vaginal => 'Vaginale',
            self::Topical => 'Cutanée / locale',
            self::Ophthalmic => 'Ophtalmique',
            self::Nasal => 'Nasale',
            self::Inhaled => 'Inhalée',
            self::Other => 'Autre',
        };
    }

    /** Compact form for a prescription line, where space is short. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Oral => 'orale',
            self::Intravenous => 'IV',
            self::Intramuscular => 'IM',
            self::Subcutaneous => 'SC',
            self::Rectal => 'rectale',
            self::Vaginal => 'vaginale',
            self::Topical => 'locale',
            self::Ophthalmic => 'ophtalmique',
            self::Nasal => 'nasale',
            self::Inhaled => 'inhalée',
            self::Other => 'autre voie',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
