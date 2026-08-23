<?php

namespace App\Enums;

/**
 * Administrative registration category selected by Reception. This value
 * identifies the additional dossier expected for the patient; it is never,
 * by itself, proof that an invoice is covered or paid.
 */
enum PatientType: string
{
    case Standard = 'STANDARD';
    case Mutual = 'MUTUAL';
    case Staff = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Patient standard',
            self::Mutual => 'Patient mutualiste',
            self::Staff => 'Personnel de la clinique',
        };
    }
}
