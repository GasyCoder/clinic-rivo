<?php

namespace App\Enums;

/**
 * ADR-212 — qui a recommandé la clinique à un nouveau patient.
 *
 * Un membre du personnel ou un partenaire est désigné par sa fiche : il peut
 * être retrouvé, et le personnel peut en tirer un bonus. Une autre personne
 * n'a pas de fiche : son nom (et son téléphone, s'il est donné) sont gardés.
 */
enum ReferralSource: string
{
    case Employee = 'EMPLOYEE';
    case Partner = 'PARTNER';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Personnel',
            self::Partner => 'Partenaire',
            self::Other => 'Autre personne',
        };
    }
}
