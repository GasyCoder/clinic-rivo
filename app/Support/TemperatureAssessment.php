<?php

namespace App\Support;

/**
 * Non-blocking temperature screening alert.
 *
 * Fever starts at 38°C in the general reference. Values below 35°C indicate
 * possible hypothermia, while 40°C or more is treated as a very high reading.
 *
 * An infant under one year is stricter: a fever is a reason for a prompt medical
 * opinion, and the normal range is narrower (WHO: 36.5–37.5 °C in the newborn).
 */
final class TemperatureAssessment
{
    public const LOW_WARNING_BELOW = 36.0;

    public const LOW_DANGER_BELOW = 35.0;

    public const FEVER_FROM = 38.0;

    public const HIGH_DANGER_FROM = 40.0;

    public const INFANT_LOW_WARNING_BELOW = 36.5;

    public const INFANT_LOW_DANGER_BELOW = 36.0;

    /** @return array<string, string>|null */
    public function classify(int|float|string|null $temperature, ?int $patientAge = null): ?array
    {
        if ($temperature === null || $temperature === '' || ! is_numeric($temperature)) {
            return null;
        }

        $value = (float) $temperature;

        if ($value < 25 || $value > 45) {
            return null;
        }

        if ($patientAge !== null && $patientAge < 1) {
            if ($value >= self::FEVER_FROM) {
                return $this->infantFever();
            }

            if ($value < self::INFANT_LOW_DANGER_BELOW) {
                return $this->infantLow(true);
            }

            if ($value < self::INFANT_LOW_WARNING_BELOW) {
                return $this->infantLow(false);
            }

            return null;
        }

        if ($value < self::LOW_DANGER_BELOW) {
            return $this->veryLow();
        }

        if ($value < self::LOW_WARNING_BELOW) {
            return $this->low();
        }

        if ($value >= self::HIGH_DANGER_FROM) {
            return $this->veryHigh();
        }

        if ($value >= self::FEVER_FROM) {
            return $this->fever();
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function reference(?int $patientAge = null): array
    {
        return [
            'patient_age' => $patientAge,
            'infant' => $patientAge !== null && $patientAge < 1,
            'infant_low_warning_below' => self::INFANT_LOW_WARNING_BELOW,
            'infant_low_danger_below' => self::INFANT_LOW_DANGER_BELOW,
            'infant_fever' => $this->infantFever(),
            'infant_low' => $this->infantLow(false),
            'infant_very_low' => $this->infantLow(true),
            'low_warning_below' => self::LOW_WARNING_BELOW,
            'low_danger_below' => self::LOW_DANGER_BELOW,
            'fever_from' => self::FEVER_FROM,
            'high_danger_from' => self::HIGH_DANGER_FROM,
            'low' => $this->low(),
            'very_low' => $this->veryLow(),
            'fever' => $this->fever(),
            'very_high' => $this->veryHigh(),
        ];
    }

    /** @return array<string, string> */
    private function infantFever(): array
    {
        return [
            'code' => 'INFANT_FEVER',
            'label' => 'Fièvre du nourrisson',
            'tone' => 'danger',
            'message' => 'Température supérieure ou égale à 38 °C chez un nourrisson de moins d’un an. Avis médical rapide ; recontrôlez la mesure.',
        ];
    }

    /** @return array<string, string> */
    private function infantLow(bool $marked): array
    {
        return [
            'code' => $marked ? 'INFANT_VERY_LOW' : 'INFANT_LOW',
            'label' => $marked ? 'Hypothermie du nourrisson' : 'Température basse du nourrisson',
            'tone' => $marked ? 'danger' : 'warning',
            'message' => $marked
                ? 'Température inférieure à 36 °C chez un nourrisson. Réchauffez, recontrôlez immédiatement et évaluez le patient.'
                : 'Température inférieure à 36,5 °C chez un nourrisson (plage usuelle 36,5–37,5 °C). Recontrôlez et couvrez l’enfant.',
        ];
    }

    /** @return array<string, string> */
    private function low(): array
    {
        return [
            'code' => 'LOW',
            'label' => 'Température basse',
            'tone' => 'warning',
            'message' => 'Température inférieure à 36 °C. Recontrôlez la mesure et évaluez le contexte clinique.',
        ];
    }

    /** @return array<string, string> */
    private function veryLow(): array
    {
        return [
            'code' => 'VERY_LOW',
            'label' => 'Hypothermie possible',
            'tone' => 'danger',
            'message' => 'Température inférieure à 35 °C. Recontrôlez immédiatement et évaluez le patient.',
        ];
    }

    /** @return array<string, string> */
    private function fever(): array
    {
        return [
            'code' => 'FEVER',
            'label' => 'Fièvre',
            'tone' => 'warning',
            'message' => 'Température supérieure ou égale à 38 °C. Recontrôlez et interprétez avec les autres signes cliniques.',
        ];
    }

    /** @return array<string, string> */
    private function veryHigh(): array
    {
        return [
            'code' => 'VERY_HIGH',
            'label' => 'Température très élevée',
            'tone' => 'danger',
            'message' => 'Température supérieure ou égale à 40 °C. Recontrôlez immédiatement et évaluez le patient.',
        ];
    }
}
