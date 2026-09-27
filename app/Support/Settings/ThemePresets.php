<?php

namespace App\Support\Settings;

/**
 * ADR-191 — les thèmes proposés. Chacun donne, pour le mode clair et le mode
 * sombre, la couleur principale, l'arrière-plan et l'avant-plan (le texte).
 *
 * « RIVO » est l'apparence d'origine : ses couleurs restent vides en base, pour
 * qu'un site qui le choisit s'affiche exactement comme avant. Les autres sont des
 * points de départ : modifier une couleur fait passer le thème en « Personnalisé ».
 * Chaque avant-plan est lisible sur son arrière-plan (contraste ≥ 7, vérifié par test).
 */
final class ThemePresets
{
    public const DEFAULT = 'rivo';

    public const CUSTOM = 'custom';

    /** Les couleurs de l'apparence d'origine (resources/css/shadcn.css), en hexadécimal. */
    public const ORIGIN = [
        'light' => ['primary' => '#287d9f', 'background' => '#f5f7fa', 'foreground' => '#243852'],
        'dark' => ['primary' => '#4fb3cf', 'background' => '#0e1520', 'foreground' => '#f1f4f8'],
    ];

    /** @return array<string, array{label: string, light: array<string, string>, dark: array<string, string>}> */
    public static function all(): array
    {
        return [
            'rivo' => ['label' => 'RIVO (d’origine)', ...self::ORIGIN],
            'ocean' => ['label' => 'Océan',
                'light' => ['primary' => '#1d6fb8', 'background' => '#f4f8fc', 'foreground' => '#1b2b3f'],
                'dark' => ['primary' => '#5aa9f0', 'background' => '#0b1624', 'foreground' => '#e8f0f8']],
            'forest' => ['label' => 'Forêt',
                'light' => ['primary' => '#2f7d4f', 'background' => '#f5f8f5', 'foreground' => '#1f3326'],
                'dark' => ['primary' => '#5cc28a', 'background' => '#0d1611', 'foreground' => '#e9f2ec']],
            'slate' => ['label' => 'Ardoise',
                'light' => ['primary' => '#475569', 'background' => '#f6f7f9', 'foreground' => '#1e293b'],
                'dark' => ['primary' => '#94a3b8', 'background' => '#0f1115', 'foreground' => '#e2e8f0']],
            'plum' => ['label' => 'Prune',
                'light' => ['primary' => '#7c3aed', 'background' => '#f8f6fc', 'foreground' => '#2a2140'],
                'dark' => ['primary' => '#a78bfa', 'background' => '#120f1a', 'foreground' => '#ece8f6']],
            'amber' => ['label' => 'Ambre',
                'light' => ['primary' => '#b45309', 'background' => '#fbf8f3', 'foreground' => '#3a2a14'],
                'dark' => ['primary' => '#f0a64a', 'background' => '#16110a', 'foreground' => '#f6efe4']],
            'night' => ['label' => 'Nuit',
                'light' => ['primary' => '#5e6ad2', 'background' => '#f7f7f9', 'foreground' => '#1c1d23'],
                'dark' => ['primary' => '#606acc', 'background' => '#0f0f11', 'foreground' => '#e3e4e6']],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return [...array_keys(self::all()), self::CUSTOM];
    }
}
