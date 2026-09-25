<?php

namespace App\Rules;

use App\Services\Settings\ThemeColor;
use App\Support\Settings\ThemePresets;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR-191 — un thème qui rendrait le texte illisible est refusé : l'avant-plan
 * doit contraster d'au moins 4,5 (WCAG AA, texte courant) avec l'arrière-plan
 * du même mode. Une couleur laissée vide vaut celle d'origine.
 */
final class ReadableThemeColors implements DataAwareRule, ValidationRule
{
    public const MINIMUM = 4.5;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $mode) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $background = $this->color('background');
        $foreground = $this->color('foreground');

        if ($background === null || $foreground === null) {
            return; // le format est vérifié par la règle voisine
        }

        $ratio = ThemeColor::contrastBetween(ThemeColor::fromHex($background), ThemeColor::fromHex($foreground));

        if ($ratio < self::MINIMUM) {
            $mode = $this->mode === 'light' ? 'du mode clair' : 'du mode sombre';
            $fail('Le texte '.$mode.' serait illisible sur son arrière-plan (contraste '.number_format($ratio, 1, ',', '').' : il faut au moins 4,5).');
        }
    }

    private function color(string $key): ?string
    {
        $value = $this->data[$this->mode.'_'.$key] ?? null;
        $value = is_string($value) && trim($value) !== '' ? trim($value) : ThemePresets::ORIGIN[$this->mode][$key];

        return ThemeColor::isValid($value) ? $value : null;
    }
}
