<?php

namespace App\Support;

/**
 * Non-blocking blood-pressure screening alert, read against the patient's age.
 *
 * These categories help the nurse notice a reading that should be checked
 * again. They never establish a diagnosis and do not block care recording.
 *
 * Adults and adolescents from 13 use the adult stages. A child's hypotension is
 * the PALS definition for their age (VitalSignAgeReference). A child under 13 has
 * no fixed high threshold — the real one is a percentile of age, sex and height —
 * so the screen is deliberately conservative and tells the nurse to compare with
 * those tables rather than pretending to classify.
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

    /** From this age the adult stages apply (AAP 2017). */
    public const ADULT_STAGES_FROM_AGE = 13;

    /** Under 13, a reading at or above these is worth a comparison with the paediatric tables. */
    public const CHILD_HIGH_SYSTOLIC_FROM = 120;

    public const CHILD_HIGH_DIASTOLIC_FROM = 80;

    /** Under 13, at or above these it is high at any age, sex and height. */
    public const CHILD_VERY_HIGH_SYSTOLIC_FROM = 140;

    public const CHILD_VERY_HIGH_DIASTOLIC_FROM = 90;

    /** @return array<string, string>|null */
    public function classify(int|float|string|null $systolic, int|float|string|null $diastolic, ?int $patientAge = null): ?array
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

        if ($patientAge !== null && $patientAge < VitalSignAgeReference::ADULT_AGE) {
            if ($systolicValue < VitalSignAgeReference::hypotensionSystolicBelow($patientAge)) {
                return $this->pediatricHypotension($patientAge);
            }

            if ($patientAge < self::ADULT_STAGES_FROM_AGE) {
                if ($systolicValue >= self::CHILD_VERY_HIGH_SYSTOLIC_FROM || $diastolicValue >= self::CHILD_VERY_HIGH_DIASTOLIC_FROM) {
                    return $this->childVeryHigh();
                }

                return $systolicValue >= self::CHILD_HIGH_SYSTOLIC_FROM || $diastolicValue >= self::CHILD_HIGH_DIASTOLIC_FROM
                    ? $this->childHigh()
                    : null;
            }
        } elseif (
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
    public function reference(?int $patientAge = null): array
    {
        $minor = $patientAge !== null && $patientAge < VitalSignAgeReference::ADULT_AGE;

        return [
            'patient_age' => $patientAge,
            'minor' => $minor,
            'child_under_13' => $patientAge !== null && $patientAge < self::ADULT_STAGES_FROM_AGE,
            'hypotension_systolic_below' => $minor ? VitalSignAgeReference::hypotensionSystolicBelow($patientAge) : null,
            'child_high_systolic_from' => self::CHILD_HIGH_SYSTOLIC_FROM,
            'child_high_diastolic_from' => self::CHILD_HIGH_DIASTOLIC_FROM,
            'child_very_high_systolic_from' => self::CHILD_VERY_HIGH_SYSTOLIC_FROM,
            'child_very_high_diastolic_from' => self::CHILD_VERY_HIGH_DIASTOLIC_FROM,
            'pediatric_hypotension' => $minor ? $this->pediatricHypotension($patientAge) : null,
            'child_high' => $this->childHigh(),
            'child_very_high' => $this->childVeryHigh(),
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
    private function pediatricHypotension(int $age): array
    {
        return [
            'code' => 'PEDIATRIC_HYPOTENSION',
            'label' => 'TA basse pour l’âge',
            'tone' => 'danger',
            'message' => sprintf(
                'Tension systolique inférieure à %d mmHg, seuil d’hypotension d’un enfant de cet âge. Recontrôlez immédiatement et évaluez la perfusion.',
                VitalSignAgeReference::hypotensionSystolicBelow($age),
            ),
        ];
    }

    /** @return array<string, string> */
    private function childHigh(): array
    {
        return [
            'code' => 'CHILD_HIGH',
            'label' => 'TA élevée pour un enfant',
            'tone' => 'warning',
            'message' => 'Tension à comparer aux tables de l’enfant (âge, sexe, taille). Recontrôlez avec un brassard adapté, l’enfant au calme.',
        ];
    }

    /** @return array<string, string> */
    private function childVeryHigh(): array
    {
        return [
            'code' => 'CHILD_VERY_HIGH',
            'label' => 'TA très élevée pour un enfant',
            'tone' => 'danger',
            'message' => 'Tension élevée quel que soit l’âge, le sexe ou la taille de l’enfant. Recontrôlez immédiatement et évaluez le patient.',
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
