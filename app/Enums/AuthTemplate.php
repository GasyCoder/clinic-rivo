<?php

namespace App\Enums;

/**
 * Le modèle des pages d'authentification d'un site (ADR-184) : connexion, mot
 * de passe oublié, réinitialisation et activation de compte suivent le même,
 * pour que l'utilisateur garde ses repères d'une page à l'autre.
 *
 * Inspirés des trois variantes DashWind, réécrits en shadcn-vue (ADR-099).
 */
enum AuthTemplate: string
{
    /** L'image occupe tout l'écran, le formulaire flotte sur une carte. */
    case Cover = 'COVER';

    /** DashWind v1/v3 : le formulaire sur un panneau, l'image à côté. */
    case Split = 'SPLIT';

    /** DashWind v2 : une carte centrée sur un fond sobre, sans image. */
    case Centered = 'CENTERED';

    public const DEFAULT = self::Cover;

    public function label(): string
    {
        return match ($this) {
            self::Cover => 'Couverture',
            self::Split => 'Partagé',
            self::Centered => 'Centré',
        };
    }

    public function usesBackground(): bool
    {
        return $this !== self::Centered;
    }
}
