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
        return $patientNumber.$this->separator.str_pad((string) $sequence, $this->episodeDigits, '0', STR_PAD_LEFT);
    }

    /** Le bébé né à la clinique : le numéro de sa mère, puis B et son rang (ADR-144). */
    public function newborn(string $motherNumber, int $rank): string
    {
        return $motherNumber.$this->separator.'B'.$rank;
    }
}
