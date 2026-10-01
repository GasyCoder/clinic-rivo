<?php

namespace App\Support\Laboratory;

/**
 * ADR-213 — lire une valeur de référence écrite en texte (« 13–17 »,
 * « 3,5 - 5,0 », « < 10 », « ≥ 40 », « 0,5 à 1,2 ») pour situer une valeur
 * numérique : BAS, NORMAL ou HAUT.
 *
 * Le catalogue ne porte que du texte, jamais de bornes structurées : une
 * référence qui ne se lit pas (« Négatif », « voir commentaire ») ne donne
 * aucune indication plutôt qu'une fausse. C'est une aide à la lecture, jamais
 * un diagnostic ni un seuil critique.
 */
final class LabReferenceRange
{
    public const LOW = 'LOW';

    public const NORMAL = 'NORMAL';

    public const HIGH = 'HIGH';

    private const NUMBER = '-?\d+(?:[.,]\d+)?';

    private function __construct(public readonly ?float $min, public readonly ?float $max) {}

    public static function parse(?string $reference): ?self
    {
        $text = trim((string) $reference);
        if ($text === '') {
            return null;
        }

        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', $text);

        if (preg_match('/^(?:de\s+)?('.self::NUMBER.')\s*(?:-|à|a|to)\s*('.self::NUMBER.')(?:\s*[^\d\s].*)?$/iu', $text, $m)) {
            $min = self::number($m[1]);
            $max = self::number($m[2]);

            return $min <= $max ? new self($min, $max) : null;
        }

        if (preg_match('/^(<|≤|<=|inf(?:érieur)?\s*(?:à|a)?)\s*('.self::NUMBER.')/iu', $text, $m)) {
            return new self(null, self::number($m[2]));
        }

        if (preg_match('/^(>|≥|>=|sup(?:érieur)?\s*(?:à|a)?)\s*('.self::NUMBER.')/iu', $text, $m)) {
            return new self(self::number($m[2]), null);
        }

        return null;
    }

    /** Une valeur saisie en nombre ? « 12,5 » vaut 12.5 ; « < 5 » n'est pas un nombre. */
    public static function numeric(?string $value): ?float
    {
        $text = trim((string) $value);

        return preg_match('/^'.self::NUMBER.'$/', $text) ? self::number($text) : null;
    }

    /** BAS, NORMAL ou HAUT ; `null` quand la valeur n'est pas un nombre. */
    public function flag(?string $value): ?string
    {
        $number = self::numeric($value);

        return match (true) {
            $number === null => null,
            $this->min !== null && $number < $this->min => self::LOW,
            $this->max !== null && $number > $this->max => self::HIGH,
            default => self::NORMAL,
        };
    }

    /** @return array{min: ?float, max: ?float} */
    public function toArray(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }

    private static function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}
