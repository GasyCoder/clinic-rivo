<?php

namespace App\Support\Numbering;

/**
 * ADR-191 — le modèle du matricule proposé à la création d'un employé
 * (`EMP-0001` par défaut). Le RH peut toujours le corriger : c'est une
 * proposition, pas une règle ; seul le caractère unique du matricule est exigé.
 */
final class EmployeeNumberFormat
{
    public const DEFAULT_PREFIX = 'EMP';

    public const SEPARATORS = ['-', '/', '.', '_'];

    public const MIN_DIGITS = 3;

    public const MAX_DIGITS = 8;

    public const DEFAULTS = ['prefix' => self::DEFAULT_PREFIX, 'separator' => '-', 'digits' => 4];

    public function __construct(
        public readonly string $prefix,
        public readonly string $separator,
        public readonly int $digits,
    ) {}

    public function format(int $counter): string
    {
        return $this->prefix.$this->separator.str_pad((string) $counter, $this->digits, '0', STR_PAD_LEFT);
    }

    /** Le compteur d'un matricule écrit selon ce modèle ; `null` s'il en suit un autre. */
    public function counterOf(string $number): ?int
    {
        $pattern = '/^'.preg_quote($this->prefix.$this->separator, '/').'(\d+)$/i';

        return preg_match($pattern, trim($number), $match) === 1 ? (int) $match[1] : null;
    }
}
