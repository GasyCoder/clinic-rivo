<?php

namespace App\Support;

/**
 * Non-blocking adult blood-pressure screening alert.
 *
 * These categories help the nurse notice a reading that should be checked
 * again. They never establish a diagnosis and do not block care recording.
 */
final class BloodPressureAssessment
{
    public const LOW_SYSTOLIC_BELOW = 90;

    public const LOW_DIASTOLIC_BELOW = 60;

    public const STAGE_ONE_SYSTOLIC_FROM = 130;

    public const STAGE_ONE_DIASTOLIC_FROM = 80;

    public const STAGE_TWO_SYSTOLIC_FROM = 140;

    public const STAGE_TWO_DIASTOLIC_FROM = 90;

    public const SEVERE_SYSTOLIC_ABOVE = 180;

    public const SEVERE_DIASTOLIC_ABOVE = 120;

    /** @return array<string, string>|null */
    public function classify(int|float|string|null $systolic, int|float|string|null $diastolic): ?array
    {
        if (! is_numeric($systolic) || ! is_numeric($diastolic)) {
            return null;
        }

        $systolicValue = (float) $systolic;
        $diastolicValue = (float) $diastolic;

        if (
            $systolicValue < 40
            || $systolicValue > 300
            || $diastolicValue < 20
            || $diastolicValue > 200
            || $systolicValue <= $diastolicValue
        ) {
            return null;
        }

        if (
            $systolicValue > self::SEVERE_SYSTOLIC_ABOVE
            || $diastolicValue > self::SEVERE_DIASTOLIC_ABOVE
        ) {
            return $this->severe();
        }

        if (
            $systolicValue < self::LOW_SYSTOLIC_BELOW
            || $diastolicValue < self::LOW_DIASTOLIC_BELOW
        ) {
            return $this->low();
        }

        if (
            $systolicValue >= self::STAGE_TWO_SYSTOLIC_FROM
            || $diastolicValue >= self::STAGE_TWO_DIASTOLIC_FROM
        ) {
            return $this->stageTwo();
        }

        if (
            $systolicValue >= self::STAGE_ONE_SYSTOLIC_FROM
            || $diastolicValue >= self::STAGE_ONE_DIASTOLIC_FROM
        ) {
            return $this->stageOne();
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function reference(): array
    {
        return [
            'low_systolic_below' => self::LOW_SYSTOLIC_BELOW,
            'low_diastolic_below' => self::LOW_DIASTOLIC_BELOW,
            'stage_one_systolic_from' => self::STAGE_ONE_SYSTOLIC_FROM,
            'stage_one_diastolic_from' => self::STAGE_ONE_DIASTOLIC_FROM,
            'stage_two_systolic_from' => self::STAGE_TWO_SYSTOLIC_FROM,
            'stage_two_diastolic_from' => self::STAGE_TWO_DIASTOLIC_FROM,
            'severe_systolic_above' => self::SEVERE_SYSTOLIC_ABOVE,
            'severe_diastolic_above' => self::SEVERE_DIASTOLIC_ABOVE,
            'low' => $this->low(),
            'stage_one' => $this->stageOne(),
            'stage_two' => $this->stageTwo(),
            'severe' => $this->severe(),
        ];
    }

    /** @return array<string, string> */
    private function low(): array
    {
        return [
            'code' => 'LOW',
            'label' => 'TA basse',
            'tone' => 'warning',
            'message' => 'Tension inférieure à 90/60 mmHg. Recontrôlez la mesure et recherchez des signes de mauvaise tolérance.',
        ];
    }

    /** @return array<string, string> */
    private function stageOne(): array
    {
        return [
            'code' => 'HIGH_STAGE_1',
            'label' => 'TA élevée',
            'tone' => 'warning',
            'message' => 'Tension au moins égale à 130/80 mmHg. Recontrôlez la mesure et interprétez-la dans le contexte clinique.',
        ];
    }

    /** @return array<string, string> */
    private function stageTwo(): array
    {
        return [
            'code' => 'HIGH_STAGE_2',
            'label' => 'TA très élevée',
            'tone' => 'warning',
            'message' => 'Tension au moins égale à 140/90 mmHg. Recontrôlez rapidement la mesure et évaluez le patient.',
        ];
    }

    /** @return array<string, string> */
    private function severe(): array
    {
        return [
            'code' => 'SEVERE_HIGH',
            'label' => 'TA sévèrement élevée',
            'tone' => 'danger',
            'message' => 'Tension supérieure à 180/120 mmHg. Recontrôlez immédiatement et recherchez des symptômes d’urgence.',
        ];
    }
}
