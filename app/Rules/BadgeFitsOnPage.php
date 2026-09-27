<?php

namespace App\Rules;

use App\Support\Hr\BadgeDesign;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ADR-209 — une planche où aucune carte ne tient n'imprimerait rien : la carte,
 * dans sa taille et son orientation, doit tenir sur le papier choisi, marges
 * comprises. Une carte par page (« Carte seule ») tient toujours.
 */
final class BadgeFitsOnPage implements DataAwareRule, ValidationRule
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
        $paper = $this->choice('badge_paper');
        [, , $defaultMargin] = BadgeDesign::NUMBERS['badge_page_margin'];
        [, , $defaultGap] = BadgeDesign::NUMBERS['badge_gap'];

        $perPage = BadgeDesign::perPage(
            $paper,
            $this->choice('badge_paper_orientation'),
            $this->choice('badge_card_size'),
            $this->choice('badge_orientation'),
            is_numeric($value) ? (int) $value : $defaultMargin,
            is_numeric($this->data['badge_gap'] ?? null) ? (int) $this->data['badge_gap'] : $defaultGap,
            ['width' => $this->data['badge_card_width'] ?? null, 'height' => $this->data['badge_card_height'] ?? null],
        );

        if ($perPage === 0) {
            $fail('Avec ces marges, la carte ne tient pas sur la page : réduisez les marges, le format du badge, ou changez de papier ou d’orientation.');
        }
    }

    private function choice(string $field): string
    {
        $value = (string) ($this->data[$field] ?? '');

        return in_array($value, BadgeDesign::CHOICES[$field], true) ? $value : BadgeDesign::CHOICES[$field][0];
    }
}
