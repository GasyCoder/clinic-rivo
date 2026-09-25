<?php

namespace App\Enums;

/**
 * ADR-183 — ce qu'est la personne derrière un compte de connexion.
 *
 * Jamais stocké sur le compte : il se lit sur le lien `employees.user_id`. Un
 * compte relié à une fiche Employé est du personnel de la clinique ; un compte
 * sans fiche est externe. Un drapeau séparé pourrait dire « personnel » sans
 * fiche, ce qui ne voudrait rien dire.
 */
enum AccountKind: string
{
    case Staff = 'STAFF';
    case External = 'EXTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Personnel clinique',
            self::External => 'Externe',
        };
    }
}
