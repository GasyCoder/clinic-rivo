<?php

namespace App\Support;

/**
 * Non-blocking pulse-oximetry screening alert.
 *
 * The thresholds follow the MedlinePlus reference: 95-100% is the usual
 * range, while 92% or less requires prompt clinical attention. Chronic lung
 * disease and altitude can change the patient's expected baseline.
 */
final class OxygenSaturationAssessment
{
    public const USUAL_MINIMUM = 95;

    public const DANGER_MAXIMUM = 92;

    /** @return array<string, string>|null */
    public function classify(int|float|string|null $spo2): ?array
    {
        if ($spo2 === null || $spo2 === '' || ! is_numeric($spo2)) {
            return null;
        }

        $value = (float) $spo2;

        if ($value < 0 || $value > 100 || $value >= self::USUAL_MINIMUM) {
            return null;
        }

        return $value <= self::DANGER_MAXIMUM
            ? $this->danger()
            : $this->warning();
    }

    /** @return array<string, mixed> */
    public function reference(): array
    {
        return [
            'usual_minimum' => self::USUAL_MINIMUM,
            'danger_maximum' => self::DANGER_MAXIMUM,
            'warning' => $this->warning(),
            'danger' => $this->danger(),
        ];
    }

    /** @return array<string, string> */
    private function warning(): array
    {
        return [
            'code' => 'LOW',
            'label' => 'SpO₂ basse',
            'tone' => 'warning',
            'message' => 'SpO₂ inférieure à 95 %. Recontrôlez la mesure et interprétez-la avec le contexte respiratoire.',
        ];
    }

    /** @return array<string, string> */
    private function danger(): array
    {
        return [
            'code' => 'VERY_LOW',
            'label' => 'SpO₂ très basse',
            'tone' => 'danger',
            'message' => 'SpO₂ inférieure ou égale à 92 %. Recontrôlez immédiatement et évaluez le patient.',
        ];
    }
}
