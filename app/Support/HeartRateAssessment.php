<?php

namespace App\Support;

/**
 * Provides a non-blocking safety alert for a low measured heart rate.
 *
 * This is a screening aid, not a diagnosis. The adult thresholds follow the
 * AHA resting-heart-rate reference and the 2025 adult bradycardia algorithm.
 * Paediatric values below 60 bpm use a distinct message because their meaning
 * depends on age and signs of cardiopulmonary compromise.
 */
final class HeartRateAssessment
{
    public const ADULT_MIN_AGE = 18;

    public const LOW_THRESHOLD = 60;

    public const MARKED_LOW_THRESHOLD = 50;

    /** @return array<string, mixed>|null */
    public function classify(int|float|string|null $heartRate, ?int $patientAge): ?array
    {
        if ($heartRate === null || ! is_numeric($heartRate) || (float) $heartRate <= 0) {
            return null;
        }

        $value = (float) $heartRate;

        if ($value >= self::LOW_THRESHOLD) {
            return null;
        }

        if ($patientAge === null) {
            return $value < self::MARKED_LOW_THRESHOLD
                ? $this->unknownAgeMarkedLow()
                : $this->unknownAgeLow();
        }

        if ($patientAge < self::ADULT_MIN_AGE) {
            return $this->pediatricLow();
        }

        return $value < self::MARKED_LOW_THRESHOLD
            ? $this->adultMarkedLow()
            : $this->adultLow();
    }

    /** @return array<string, mixed> */
    public function reference(?int $patientAge): array
    {
        return [
            'patient_age' => $patientAge,
            'adult_min_age' => self::ADULT_MIN_AGE,
            'low_threshold' => self::LOW_THRESHOLD,
            'marked_low_threshold' => self::MARKED_LOW_THRESHOLD,
            'adult_low' => $this->adultLow(),
            'adult_marked_low' => $this->adultMarkedLow(),
            'pediatric_low' => $this->pediatricLow(),
            'age_unknown_low' => $this->unknownAgeLow(),
            'age_unknown_marked_low' => $this->unknownAgeMarkedLow(),
            'disclaimer' => 'Alerte de dépistage à interpréter avec les symptômes, le contexte et les autres constantes ; elle ne constitue pas un diagnostic.',
        ];
    }

    /** @return array<string, string> */
    private function adultLow(): array
    {
        return [
            'code' => 'LOW',
            'label' => 'FC basse',
            'tone' => 'warning',
            'message' => 'Fréquence cardiaque inférieure à 60 bpm. Recontrôlez la mesure et évaluez le contexte clinique.',
        ];
    }

    /** @return array<string, string> */
    private function adultMarkedLow(): array
    {
        return [
            'code' => 'MARKEDLY_LOW',
            'label' => 'FC très basse',
            'tone' => 'danger',
            'message' => 'Fréquence cardiaque inférieure à 50 bpm. Recontrôlez immédiatement et recherchez des signes de mauvaise tolérance.',
        ];
    }

    /** @return array<string, string> */
    private function pediatricLow(): array
    {
        return [
            'code' => 'PEDIATRIC_LOW',
            'label' => 'FC basse — enfant',
            'tone' => 'danger',
            'message' => 'Fréquence cardiaque inférieure à 60 bpm chez un patient mineur. Vérifiez immédiatement la mesure, l’âge précis et les signes de mauvaise tolérance.',
        ];
    }

    /** @return array<string, string> */
    private function unknownAgeLow(): array
    {
        return [
            'code' => 'LOW_AGE_UNKNOWN',
            'label' => 'FC basse à vérifier',
            'tone' => 'warning',
            'message' => 'Fréquence cardiaque inférieure à 60 bpm. L’âge est nécessaire pour contextualiser l’alerte ; recontrôlez la mesure.',
        ];
    }

    /** @return array<string, string> */
    private function unknownAgeMarkedLow(): array
    {
        return [
            'code' => 'MARKEDLY_LOW_AGE_UNKNOWN',
            'label' => 'FC très basse à vérifier',
            'tone' => 'danger',
            'message' => 'Fréquence cardiaque inférieure à 50 bpm. Recontrôlez immédiatement la mesure et renseignez l’âge pour l’interpréter.',
        ];
    }
}
