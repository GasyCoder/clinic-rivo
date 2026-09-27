<?php

namespace App\Services\Settings;

/**
 * Une couleur de marque et ses déclinaisons pour le thème (ADR-184).
 *
 * Les variables de l'interface sont des triplets HSL (`197 60% 39%`) : la
 * couleur choisie est convertie, puis déclinée pour le thème sombre — plus
 * claire, pour rester lisible sur un fond foncé. Le texte posé sur la couleur
 * (blanc ou quasi noir) est choisi par contraste WCAG, jamais supposé : une
 * couleur trop claire n'aurait sinon que des boutons illisibles.
 */
final class ThemeColor
{
    /** Le texte foncé de l'interface, repris du thème (`--foreground` sombre). */
    private const DARK_TEXT = [215, 40, 10];

    private function __construct(
        private readonly float $hue,
        private readonly float $saturation,
        private readonly float $lightness,
    ) {}

    public static function isValid(string $hex): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    public static function fromHex(string $hex): self
    {
        [$r, $g, $b] = array_map(fn (string $pair) => hexdec($pair) / 255, str_split(substr($hex, 1), 2));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta == 0) {
            return new self(0, 0, $lightness * 100);
        }

        $saturation = $delta / (1 - abs(2 * $lightness - 1));
        $hue = match (true) {
            $max === $r => 60 * fmod(($g - $b) / $delta, 6),
            $max === $g => 60 * (($b - $r) / $delta + 2),
            default => 60 * (($r - $g) / $delta + 4),
        };

        return new self($hue < 0 ? $hue + 360 : $hue, $saturation * 100, $lightness * 100);
    }

    public function light(): string
    {
        return $this->format($this->hue, $this->saturation, $this->lightness);
    }

    public function lightForeground(): string
    {
        return $this->readableTextOn($this->hue, $this->saturation, $this->lightness);
    }

    public function lightAccent(): string
    {
        return $this->format($this->hue, min($this->saturation, 70), 94);
    }

    public function lightAccentForeground(): string
    {
        return $this->format($this->hue, min($this->saturation, 60), 28);
    }

    public function dark(): string
    {
        return $this->format($this->hue, $this->saturation, $this->darkLightness());
    }

    public function darkForeground(): string
    {
        return $this->readableTextOn($this->hue, $this->saturation, $this->darkLightness());
    }

    public function darkAccent(): string
    {
        return $this->format($this->hue, min($this->saturation, 45), 19);
    }

    public function darkAccentForeground(): string
    {
        return $this->format($this->hue, min($this->saturation, 65), 76);
    }

    public function hue(): float
    {
        return $this->hue;
    }

    public function saturation(): float
    {
        return $this->saturation;
    }

    public function lightness(): float
    {
        return $this->lightness;
    }

    /** La couleur en triplet HSL, tel que les variables de l'interface l'attendent. */
    public function triplet(): string
    {
        return $this->format($this->hue, $this->saturation, $this->lightness);
    }

    /** Luminance relative WCAG. */
    public function relativeLuminance(): float
    {
        return self::luminance($this->hue, $this->saturation, $this->lightness);
    }

    /** Le contraste WCAG entre deux couleurs (4,5 : texte courant lisible ; 3 : gros texte). */
    public static function contrastBetween(self $a, self $b): float
    {
        return self::contrast($a->relativeLuminance(), $b->relativeLuminance());
    }

    /** Un triplet HSL borné (teinte 0-360, saturation et luminosité 0-100). */
    public static function hsl(float $h, float $s, float $l): string
    {
        return sprintf('%d %d%% %d%%', (int) round(fmod($h + 360, 360)), (int) round(max(0, min(100, $s))), (int) round(max(0, min(100, $l))));
    }

    /** Le contraste WCAG entre la couleur et un texte blanc (≥ 4,5 : texte courant lisible). */
    public function contrastWithWhite(): float
    {
        return self::contrast(self::luminance($this->hue, $this->saturation, $this->lightness), 1.0);
    }

    /** Plus claire sur fond sombre, sans devenir pastel. */
    private function darkLightness(): float
    {
        return max(55, min(72, $this->lightness + 17));
    }

    private function readableTextOn(float $h, float $s, float $l): string
    {
        $background = self::luminance($h, $s, $l);
        $white = self::contrast($background, 1.0);
        $dark = self::contrast($background, self::luminance(...self::DARK_TEXT));

        return $white >= 4.5 || $white >= $dark ? '0 0% 100%' : $this->format(...self::DARK_TEXT);
    }

    private function format(float $h, float $s, float $l): string
    {
        return sprintf('%d %d%% %d%%', (int) round($h), (int) round($s), (int) round($l));
    }

    private static function contrast(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** Luminance relative WCAG d'une couleur HSL. */
    private static function luminance(float $h, float $s, float $l): float
    {
        $s /= 100;
        $l /= 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        $channel = function (float $value) use ($m): float {
            $value += $m;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }
}
