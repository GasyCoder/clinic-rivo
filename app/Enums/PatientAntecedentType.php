<?php

namespace App\Enums;

/**
 * The clinic's "DOSSIER MÉDICAL" form separates the patient's own history
 * from what runs in the family — two different clinical readings, so two
 * distinct lists rather than one undifferentiated block.
 *
 * Rows recorded before this distinction existed are classified `PERSONAL`:
 * that is what the form they were entered on collected. Guessing which of
 * them were actually familial would falsify a clinical history.
 */
enum PatientAntecedentType: string
{
    case Personal = 'PERSONAL';
    case Familial = 'FAMILIAL';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Antécédent personnel',
            self::Familial => 'Antécédent familial',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Personal => 'Personnel',
            self::Familial => 'Familial',
        };
    }
}
