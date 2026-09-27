<?php

namespace App\Enums;

/**
 * What the doctor actually did with one body system.
 *
 * The three states are not interchangeable and the distinction is the whole
 * point of this enum: an absence of entry is never a normal result. A system
 * only reads NORMAL because a doctor said so.
 */
enum ClinicalSystemStatus: string
{
    case NotExamined = 'NOT_EXAMINED';
    case Normal = 'NORMAL';
    case Abnormal = 'ABNORMAL';

    public function label(): string
    {
        return match ($this) {
            self::NotExamined => 'Non examiné',
            self::Normal => 'Normal',
            self::Abnormal => 'Anormal',
        };
    }

    /** Whether the doctor actually examined this system, either way. */
    public function isExamined(): bool
    {
        return $this !== self::NotExamined;
    }

    /**
     * An anomaly without its findings is an unusable record: the reader learns
     * that something is wrong but not what. Only ABNORMAL demands the text.
     */
    public function requiresFindings(): bool
    {
        return $this === self::Abnormal;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
