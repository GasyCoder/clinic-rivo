<?php

namespace App\Enums;

/**
 * Le modèle de la page « Mon profil » d'un site (ADR-184). Le contenu ne change
 * pas — identité, droits, sécurité — seule sa disposition.
 *
 * Inspirés des pages de profil DashWind, réécrits en shadcn-vue (ADR-099).
 */
enum ProfileTemplate: string
{
    /** DashWind « user-profile-regular » : une carte et un menu à gauche, la section à droite. */
    case Sidebar = 'SIDEBAR';

    /** Un bandeau au nom de l'utilisateur, puis des onglets. */
    case Banner = 'BANNER';

    public const DEFAULT = self::Sidebar;

    public function label(): string
    {
        return match ($this) {
            self::Sidebar => 'Barre latérale',
            self::Banner => 'Bandeau et onglets',
        };
    }
}
