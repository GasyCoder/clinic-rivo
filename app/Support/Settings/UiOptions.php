<?php

namespace App\Support\Settings;

/**
 * ADR-191 — les réglages « Avancé » de l'interface et leurs valeurs permises.
 *
 * Le site fixe les valeurs par défaut ; chaque utilisateur ajuste, dans « Mon
 * profil », ce qui ne dépend que de lui : la taille du texte, les animations et
 * le contraste. La densité et les arrondis restent ceux du site.
 */
final class UiOptions
{
    /** Taille de référence de l'interface, en px (16 : celle du navigateur, et d'avant). */
    public const FONT_SIZES = [14, 15, 16, 17, 18];

    public const DEFAULT_FONT_SIZE = 16;

    /** Hauteur des champs et des boutons. */
    public const DENSITIES = ['compact', 'default', 'comfortable'];

    /** Arrondi des cartes, champs et boutons. */
    public const RADII = ['square', 'default', 'round'];

    /** `system` suit le réglage de l'appareil ; `reduce` coupe les animations ; `full` les garde. */
    public const MOTIONS = ['system', 'reduce', 'full'];

    /** Bordures et texte secondaire plus marqués, pour la lisibilité. */
    public const CONTRASTS = ['standard', 'high', 'max'];

    public const DEFAULTS = [
        'font_size' => self::DEFAULT_FONT_SIZE,
        'density' => 'default',
        'radius' => 'default',
        'motion' => 'system',
        'contrast' => 'standard',
    ];

    /** Ce que chacun peut ajuster pour lui-même (le reste appartient au site). */
    public const PERSONAL = ['font_size', 'motion', 'contrast'];

    /** Une valeur reçue, gardée seulement si elle est permise ; sinon `null` (= celle du site). */
    public static function clean(string $key, mixed $value): int|string|null
    {
        return match ($key) {
            'font_size' => in_array((int) $value, self::FONT_SIZES, true) ? (int) $value : null,
            'density' => in_array($value, self::DENSITIES, true) ? $value : null,
            'radius' => in_array($value, self::RADII, true) ? $value : null,
            'motion' => in_array($value, self::MOTIONS, true) ? $value : null,
            'contrast' => in_array($value, self::CONTRASTS, true) ? $value : null,
            default => null,
        };
    }
}
