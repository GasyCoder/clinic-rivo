<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use App\Support\Settings\ThemePresets;

/**
 * ADR-191 — le thème d'un site, traduit en variables de l'interface.
 *
 * Pour chaque mode (clair, sombre), trois couleurs sont réglées : la couleur
 * principale, l'arrière-plan et l'avant-plan (le texte). Les surfaces — cartes,
 * zones atténuées, bordures, texte secondaire — en sont déduites, dans le sens
 * de l'arrière-plan : plus foncées sur un fond clair, plus claires sur un fond
 * sombre. Avec les couleurs d'origine, le calcul retrouve à 1 ou 2 % près les
 * valeurs écrites à la main dans resources/css/shadcn.css.
 *
 * Seul ce qui est réglé est redéfini : un site qui garde le thème d'origine ne
 * reçoit que les règles de contraste renforcé, que chacun peut choisir.
 */
final class ThemePalette
{
    /** Écart de luminosité des bordures, des champs et part du texte secondaire, par niveau de contraste. */
    private const CONTRAST = [
        'light' => ['standard' => [7, 9, 0.35], 'high' => [16, 19, 0.22], 'max' => [30, 33, 0.1]],
        'dark' => ['standard' => [12, 15, 0.35], 'high' => [22, 26, 0.22], 'max' => [35, 38, 0.1]],
    ];

    /**
     * @param  array{primary: ?string, background: ?string, foreground: ?string}  $light
     * @param  array{primary: ?string, background: ?string, foreground: ?string}  $dark
     */
    public function __construct(private readonly array $light, private readonly array $dark) {}

    public static function fromSetting(?AppSetting $setting): self
    {
        $hex = fn (?string $value): ?string => $value !== null && ThemeColor::isValid($value) ? $value : null;

        return new self(
            ['primary' => $hex($setting?->primary_color), 'background' => $hex($setting?->light_background), 'foreground' => $hex($setting?->light_foreground)],
            ['primary' => $hex($setting?->dark_primary_color), 'background' => $hex($setting?->dark_background), 'foreground' => $hex($setting?->dark_foreground)],
        );
    }

    /** Les règles CSS du thème ; toujours au moins celles du contraste renforcé. */
    public function css(): string
    {
        $css = '';

        if ($light = $this->modeTokens('light')) {
            $css .= ':root{'.$this->declare($light).'}';
        }

        if ($dark = $this->modeTokens('dark')) {
            $css .= '.dark{'.$this->declare($dark).'}';
        }

        foreach (['high', 'max'] as $level) {
            $css .= 'html[data-contrast="'.$level.'"]{'.$this->declare($this->contrastTokens('light', $level)).'}';
            $css .= 'html[data-contrast="'.$level.'"].dark{'.$this->declare($this->contrastTokens('dark', $level)).'}';
        }

        return $css;
    }

    /**
     * Les variables d'un mode, pour un aperçu : toutes, réglées ou d'origine.
     *
     * @return array<string, string>
     */
    public function previewTokens(string $mode): array
    {
        return [
            ...$this->primaryTokens($mode, force: true),
            ...$this->surfaceTokens($this->effective($mode, 'background'), $this->effective($mode, 'foreground'), $mode, 'standard'),
        ];
    }

    /** @return array<string, string> */
    private function modeTokens(string $mode): array
    {
        $palette = $mode === 'light' ? $this->light : $this->dark;
        $tokens = $this->primaryTokens($mode);

        if ($palette['background'] !== null || $palette['foreground'] !== null) {
            $tokens += $this->surfaceTokens($this->effective($mode, 'background'), $this->effective($mode, 'foreground'), $mode, 'standard');
        }

        return $tokens;
    }

    /**
     * La couleur principale et ce qui en découle. En mode sombre sans couleur
     * propre, elle est déduite de celle du mode clair (plus claire, lisible sur
     * un fond foncé), comme avant l'ADR-191.
     *
     * @return array<string, string>
     */
    private function primaryTokens(string $mode, bool $force = false): array
    {
        $own = $mode === 'light' ? $this->light['primary'] : $this->dark['primary'];

        if ($own !== null || ($force && $mode === 'light')) {
            $color = ThemeColor::fromHex($own ?? ThemePresets::ORIGIN['light']['primary']);
            $accent = $mode === 'light'
                ? [$color->lightAccent(), $color->lightAccentForeground()]
                : [$color->darkAccent(), $color->darkAccentForeground()];

            return [
                '--primary' => $color->triplet(), '--ring' => $color->triplet(),
                '--primary-foreground' => $color->lightForeground(),
                '--accent' => $accent[0], '--accent-foreground' => $accent[1],
            ];
        }

        if ($mode === 'dark' && ($this->light['primary'] !== null || $force)) {
            $color = ThemeColor::fromHex($this->light['primary'] ?? ThemePresets::ORIGIN['light']['primary']);

            return [
                '--primary' => $color->dark(), '--ring' => $color->dark(),
                '--primary-foreground' => $color->darkForeground(),
                '--accent' => $color->darkAccent(), '--accent-foreground' => $color->darkAccentForeground(),
            ];
        }

        return [];
    }

    /** @return array<string, string> */
    private function surfaceTokens(string $backgroundHex, string $foregroundHex, string $mode, string $level): array
    {
        $bg = ThemeColor::fromHex($backgroundHex);
        $fg = ThemeColor::fromHex($foregroundHex);
        $lightBackground = $bg->lightness() >= 50;
        $step = $lightBackground ? -1 : 1;
        [$border, $input, $mutedShare] = self::CONTRAST[$lightBackground ? 'light' : 'dark'][$level];

        $card = ThemeColor::hsl($bg->hue(), $bg->saturation(), $lightBackground ? min(100, $bg->lightness() + 3) : $bg->lightness() + 3);
        $muted = ThemeColor::hsl($bg->hue(), $lightBackground ? min($bg->saturation() + 10, 50) : $bg->saturation() * 0.7, $lightBackground ? $bg->lightness() - 1 : $bg->lightness() + 9);

        return [
            '--background' => $bg->triplet(),
            '--foreground' => $fg->triplet(),
            '--card' => $card, '--card-foreground' => $fg->triplet(),
            '--popover' => $card, '--popover-foreground' => $fg->triplet(),
            '--secondary' => $muted, '--secondary-foreground' => $fg->triplet(),
            '--muted' => $muted,
            ...$this->edgeTokens($bg, $fg, $border, $input, $mutedShare, $step, $lightBackground),
        ];
    }

    /** @return array<string, string> */
    private function contrastTokens(string $mode, string $level): array
    {
        $bg = ThemeColor::fromHex($this->effective($mode, 'background'));
        $fg = ThemeColor::fromHex($this->effective($mode, 'foreground'));
        $lightBackground = $bg->lightness() >= 50;
        [$border, $input, $mutedShare] = self::CONTRAST[$lightBackground ? 'light' : 'dark'][$level];

        return $this->edgeTokens($bg, $fg, $border, $input, $mutedShare, $lightBackground ? -1 : 1, $lightBackground);
    }

    /** @return array<string, string> */
    private function edgeTokens(ThemeColor $bg, ThemeColor $fg, int $border, int $input, float $mutedShare, int $step, bool $lightBackground): array
    {
        $edgeSaturation = $lightBackground ? $bg->saturation() : $bg->saturation() * 0.6;

        return [
            '--muted-foreground' => ThemeColor::hsl($fg->hue(), min($fg->saturation(), 25), $fg->lightness() + ($bg->lightness() - $fg->lightness()) * $mutedShare),
            '--border' => ThemeColor::hsl($bg->hue(), $edgeSaturation, $bg->lightness() + $step * $border),
            '--input' => ThemeColor::hsl($bg->hue(), $edgeSaturation, $bg->lightness() + $step * $input),
        ];
    }

    private function effective(string $mode, string $key): string
    {
        $palette = $mode === 'light' ? $this->light : $this->dark;

        return $palette[$key] ?? ThemePresets::ORIGIN[$mode][$key];
    }

    /** @param array<string, string> $tokens */
    private function declare(array $tokens): string
    {
        return collect($tokens)->map(fn (string $value, string $name) => "{$name}:{$value};")->implode('');
    }
}
