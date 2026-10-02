<?php

namespace App\Support\Pharmacy;

/**
 * ADR-242 — comment le comparateur des fournisseurs montre le moins cher et le
 * plus cher : couleurs, marquage, écart affiché, tri. Un réglage d'affichage,
 * gardé sur le compte (`users.ui_preferences.price_comparison`) et jamais sur le
 * poste : un poste est partagé. Aucune règle d'achat n'en dépend — le choix du
 * fournisseur reste celui de l'acheteur (ADR-098).
 *
 * La même liste vit dans `resources/js/utilities/priceComparison.js`.
 */
final class PriceComparisonPreferences
{
    public const KEY = 'price_comparison';

    public const STYLES = ['TINT', 'TEXT', 'BORDER'];

    public const GAPS = ['PERCENT', 'AMOUNT', 'BOTH', 'NONE'];

    public const DEFAULTS = [
        'best_color' => '#059669',
        'middle_color' => '#D97706',
        'worst_color' => '#DC2626',
        'color_middle' => true,
        'style' => 'TINT',
        'show_gap' => 'PERCENT',
        'min_gap_percent' => 0,
        'show_labels' => true,
        'sort_by_price' => true,
        'show_legend' => true,
    ];

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        $color = ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'best_color' => $color,
            'middle_color' => $color,
            'worst_color' => $color,
            'color_middle' => ['nullable', 'boolean'],
            'style' => ['nullable', 'in:'.implode(',', self::STYLES)],
            'show_gap' => ['nullable', 'in:'.implode(',', self::GAPS)],
            'min_gap_percent' => ['nullable', 'integer', 'min:0', 'max:500'],
            'show_labels' => ['nullable', 'boolean'],
            'sort_by_price' => ['nullable', 'boolean'],
            'show_legend' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Ce qui s'écarte des valeurs d'origine, seulement : un réglage resté
     * d'origine suit d'éventuels changements de l'application.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function clean(array $values): array
    {
        $kept = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $values) || $values[$key] === null || $values[$key] === '') {
                continue;
            }

            $value = match (true) {
                is_bool($default) => filter_var($values[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                is_int($default) => is_numeric($values[$key]) ? max(0, min(500, (int) $values[$key])) : null,
                str_ends_with($key, '_color') => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $values[$key]) ? strtoupper((string) $values[$key]) : null,
                $key === 'style' => in_array($values[$key], self::STYLES, true) ? $values[$key] : null,
                $key === 'show_gap' => in_array($values[$key], self::GAPS, true) ? $values[$key] : null,
                default => null,
            };

            if ($value !== null && $value !== $default) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * Les réglages d'un compte, complétés des valeurs d'origine.
     *
     * @param  array<string, mixed>|null  $preferences  users.ui_preferences
     * @return array<string, mixed>
     */
    public static function resolve(?array $preferences): array
    {
        $stored = is_array($preferences[self::KEY] ?? null) ? $preferences[self::KEY] : [];

        return array_merge(self::DEFAULTS, self::clean($stored));
    }
}
