<?php

namespace App\Support\Numbering;

/**
 * ADR-191 — la forme des numéros de patient, de passage et de nouveau-né d'un site.
 *
 * Par défaut, celle de l'ADR-030 : `A-26-0001` (code du site, année sur deux
 * chiffres, compteur sur quatre, remis à 1 chaque année) et `A-26-0001-01` pour
 * un passage. Un réglage ne vaut que pour les numéros à venir : aucun numéro déjà
 * attribué n'est réécrit, et le générateur saute tout numéro qui existerait déjà.
 */
final class PatientNumberFormat
{
    public const YEARS = ['none', '2', '4'];

    public const SEPARATORS = ['-', '/', '.', '_'];

    public const RESETS = ['yearly', 'never'];

    public const MIN_DIGITS = 3;

    public const MAX_DIGITS = 8;

    public const DEFAULTS = ['year' => '2', 'digits' => 4, 'separator' => '-', 'reset' => 'yearly', 'episode_digits' => 2];

    public function __construct(
        public readonly string $prefix,
        public readonly string $year,
        public readonly int $digits,
        public readonly string $separator,
        public readonly string $reset,
        public readonly int $episodeDigits,
    ) {}

    /** Le compteur utilisé : celui de l'année, ou un compteur unique (clé 0) sans remise à 1. */
    public function sequenceKey(int $year): int
    {
        return $this->reset === 'yearly' ? $year : 0;
    }

    public function patient(int $year, int $counter): string
    {
        $parts = [$this->prefix];

        if ($this->year !== 'none') {
            $parts[] = $this->year === '4' ? (string) $year : sprintf('%02d', $year % 100);
        }

        $parts[] = str_pad((string) $counter, $this->digits, '0', STR_PAD_LEFT);

        return implode($this->separator, array_filter($parts, fn (string $part) => $part !== ''));
    }

    public function episode(string $patientNumber, int $sequence): string
    {
        return $patientNumber.$this->separatorOf($patientNumber).str_pad((string) $sequence, $this->episodeDigits, '0', STR_PAD_LEFT);
    }

    /** Le bébé né à la clinique : le numéro de sa mère, puis B et son rang (ADR-144). */
    public function newborn(string $motherNumber, int $rank): string
    {
        return $motherNumber.$this->separatorOf($motherNumber).'B'.$rank;
    }

    /**
     * Le séparateur avec lequel un numéro déjà attribué a été écrit.
     *
     * Un passage ou un bébé prolonge le numéro de son patient dans la forme de
     * ce numéro : changer le séparateur ne vaut que pour les nouveaux patients,
     * et ne fabrique jamais « A_26_001-002 ». Le préfixe n'a que des lettres et
     * des chiffres, donc le premier autre caractère est le séparateur. Un numéro
     * qui n'en a aucun (préfixe vide, sans année) prend celui du réglage.
     */
    public function separatorOf(string $number): string
    {
        return preg_match('/[^A-Za-z0-9]/', $number, $match) === 1 && in_array($match[0], self::SEPARATORS, true)
            ? $match[0]
            : $this->separator;
    }
}
