<?php

namespace App\Support;

/**
 * Non-blocking heart-rate alert, low or high, read against the patient's age.
 *
 * This is a screening aid, not a diagnosis. The adult thresholds follow the
 * AHA resting-heart-rate reference and the 2025 adult bradycardia algorithm.
 * A child is read against the PALS range for their age (VitalSignAgeReference)
 * — a rate that is normal at 8 months is far too fast at 12 years, and the
 * reverse. Below 60 bpm a minor is always flagged, whatever the age.
 */
final class HeartRateAssessment
{
    public const ADULT_MIN_AGE = 18;

    public const LOW_THRESHOLD = 60;

    public const MARKED_LOW_THRESHOLD = 50;

    public const ADULT_HIGH_THRESHOLD = 100;

    /** @return array<string, mixed>|null */
    public function classify(int|float|string|null $heartRate, ?int $patientAge): ?array
    {
        if ($heartRate === null || ! is_numeric($heartRate) || (float) $heartRate <= 0) {
            return null;
        }

        $value = (float) $heartRate;

        if ($patientAge !== null && $patientAge < self::ADULT_MIN_AGE) {
            return $this->classifyMinor($value, $patientAge);
        }

        if ($patientAge !== null && $value > self::ADULT_HIGH_THRESHOLD) {
            return $value > self::ADULT_HIGH_THRESHOLD * VitalSignAgeReference::MARKED_HIGH_FACTOR
                ? $this->high($patientAge, self::ADULT_HIGH_THRESHOLD, true)
                : $this->high($patientAge, self::ADULT_HIGH_THRESHOLD, false);
        }

        if ($value >= self::LOW_THRESHOLD) {
            return null;
        }

        if ($patientAge === null) {
            return $value < self::MARKED_LOW_THRESHOLD
                ? $this->unknownAgeMarkedLow()
                : $this->unknownAgeLow();
        }

        return $value < self::MARKED_LOW_THRESHOLD
            ? $this->adultMarkedLow()
            : $this->adultLow();
    }

    /**
     * Un mineur : sous 60 bpm, toujours alerté ; sinon lu contre la plage de son âge.
     *
     * @return array<string, mixed>|null
     */
    private function classifyMinor(float $value, int $age): ?array
    {
        if ($value < self::LOW_THRESHOLD) {
            return $this->pediatricLow();
        }

        [$min, $max] = VitalSignAgeReference::heartRateRange($age);

        if ($value < $min) {
            return $this->lowForAge($age, $min, $value < $min * VitalSignAgeReference::MARKED_LOW_FACTOR);
        }

        if ($value > $max) {
            return $this->high($age, $max, $value > $max * VitalSignAgeReference::MARKED_HIGH_FACTOR);
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function reference(?int $patientAge): array
    {
        $range = $patientAge === null || $patientAge >= self::ADULT_MIN_AGE
            ? [self::LOW_THRESHOLD, self::ADULT_HIGH_THRESHOLD]
            : VitalSignAgeReference::heartRateRange($patientAge);

        return [
            'age_range' => $patientAge === null ? null : ['min' => $range[0], 'max' => $range[1]],
            'marked_low_factor' => VitalSignAgeReference::MARKED_LOW_FACTOR,
            'marked_high_factor' => VitalSignAgeReference::MARKED_HIGH_FACTOR,
            'adult_high_threshold' => self::ADULT_HIGH_THRESHOLD,
            'low_for_age' => $patientAge === null ? null : $this->lowForAge($patientAge, $range[0], false),
            'marked_low_for_age' => $patientAge === null ? null : $this->lowForAge($patientAge, $range[0], true),
            'high' => $patientAge === null ? null : $this->high($patientAge, $range[1], false),
            'marked_high' => $patientAge === null ? null : $this->high($patientAge, $range[1], true),
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
    private function lowForAge(int $age, int $min, bool $marked): array
    {
        $band = VitalSignAgeReference::band($age);

        return [
            'code' => $marked ? 'MARKEDLY_LOW_FOR_AGE' : 'LOW_FOR_AGE',
            'label' => $marked ? 'FC très basse pour l’âge' : 'FC basse pour l’âge',
            'tone' => $marked ? 'danger' : 'warning',
            'message' => sprintf(
                'Fréquence cardiaque inférieure à %d bpm, plage usuelle d’un %s de %s. Recontrôlez la mesure et recherchez des signes de mauvaise tolérance.',
                $min,
                $band,
                $this->ageLabel($age),
            ),
        ];
    }

    /** @return array<string, string> */
    private function high(int $age, int $max, bool $marked): array
    {
        $adult = $age >= self::ADULT_MIN_AGE;

        return [
            'code' => $marked ? 'MARKEDLY_HIGH' : 'HIGH',
            'label' => $marked ? 'FC très élevée' : ($adult ? 'FC élevée' : 'FC élevée pour l’âge'),
            'tone' => $marked ? 'danger' : 'warning',
            'message' => sprintf(
                'Fréquence cardiaque supérieure à %d bpm%s. Recontrôlez au calme et recherchez fièvre, douleur, déshydratation, anxiété ou signes de mauvaise tolérance.',
                $max,
                $adult ? '' : sprintf(', plage usuelle d’un %s de %s', VitalSignAgeReference::band($age), $this->ageLabel($age)),
            ),
        ];
    }

    private function ageLabel(int $age): string
    {
        return $age < 1 ? 'moins d’un an' : ($age === 1 ? '1 an' : $age.' ans');
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
