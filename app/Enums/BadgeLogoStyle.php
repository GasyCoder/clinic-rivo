<?php

namespace App\Enums;

/**
 * ADR-209 — comment le badge du personnel montre l'établissement.
 */
enum BadgeLogoStyle: string
{
    /** L'emblème dans un sceau rond, le nom de l'établissement écrit autour (le modèle de la clinique). */
    case Seal = 'SEAL';

    /** L'image déposée telle quelle, sans sceau : pour un logo en largeur. */
    case Logo = 'LOGO';

    /** Ni sceau ni logo : le coin reste aux bandes et à la devise. */
    case None = 'NONE';

    public const DEFAULT = self::Seal;

    public function label(): string
    {
        return match ($this) {
            self::Seal => 'Sceau',
            self::Logo => 'Logo seul',
            self::None => 'Aucun',
        };
    }
}
