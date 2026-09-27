<?php

namespace App\Rules;

use App\Support\Hr\BadgeDesign;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR-209 — un badge sur mesure se met en page tant que son côté long reste entre
 * 1,15 et 1,8 fois son côté court : plus carré ou plus allongé, la photo et le nom
 * n'y tiennent plus lisiblement. La règle ne vaut que pour « Sur mesure » : les
 * formats proposés sont déjà dans ces bornes.
 */
final class BadgeCustomFormat implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (($this->data['badge_card_size'] ?? null) !== 'CUSTOM') {
            return;
        }

        $width = $this->data['badge_card_width'] ?? null;
        $height = $this->data['badge_card_height'] ?? null;

        if (! is_numeric($width) || ! is_numeric($height)) {
            $fail('Indiquez les deux côtés du format sur mesure, en millimètres.');

            return;
        }

        [$min, $max] = BadgeDesign::PROPORTION;
        $ratio = max((float) $width, (float) $height) / max(1.0, min((float) $width, (float) $height));

        if ($ratio < $min || $ratio > $max) {
            $fail(sprintf(
                'Le côté long doit mesurer entre %s et %s fois le côté court (ici %s) : un badge plus carré ou plus allongé ne se met pas en page.',
                str_replace('.', ',', (string) $min),
                str_replace('.', ',', (string) $max),
                str_replace('.', ',', (string) round($ratio, 2)),
            ));
        }
    }
}
