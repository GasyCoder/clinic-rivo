<?php

namespace App\Support;

/**
 * Interprets BMI as a screening indicator, without turning it into a diagnosis.
 *
 * Fixed adult thresholds follow the WHO reference. Patients under 20 years old
 * require an age- and sex-specific BMI-for-age assessment and are therefore
 * never classified with the adult bands here.
 */
final class BmiAssessment
{
    public const ADULT_MIN_AGE = 20;

    /** @return array<string, mixed>|null */
    public function classify(int|float|string|null $bmi, ?int $patientAge): ?array
    {
        if ($bmi === null || ! is_numeric($bmi) || (float) $bmi <= 0) {
            return null;
        }

        if ($patientAge === null) {
            return $this->ageUnknown();
        }

        if ($patientAge < self::ADULT_MIN_AGE) {
            return $this->pediatricReview();
        }

        $value = (float) $bmi;

        foreach ($this->adultBands() as $band) {
            if (($band['minimum'] === null || $value >= $band['minimum'])
                && ($band['maximum'] === null || $value < $band['maximum'])) {
                return $band;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function reference(?int $patientAge): array
    {
        return [
            'patient_age' => $patientAge,
            'adult_min_age' => self::ADULT_MIN_AGE,
            'adult_bands' => $this->adultBands(),
            'pediatric' => $this->pediatricReview(),
            'age_unknown' => $this->ageUnknown(),
            'disclaimer' => 'Indicateur de dépistage à interpréter avec le contexte clinique ; il ne constitue pas un diagnostic.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function adultBands(): array
    {
        return [
            [
                'code' => 'UNDERWEIGHT',
                'label' => 'Insuffisance pondérale',
                'minimum' => null,
                'maximum' => 18.5,
                'tone' => 'warning',
                'message' => 'IMC inférieur à 18,5. Une évaluation nutritionnelle et clinique est recommandée.',
            ],
            [
                'code' => 'NORMAL',
                'label' => 'Corpulence normale',
                'minimum' => 18.5,
                'maximum' => 25.0,
                'tone' => 'success',
                'message' => 'IMC compris entre 18,5 et 24,9, dans la zone de corpulence normale.',
            ],
            [
                'code' => 'OVERWEIGHT',
                'label' => 'Surpoids',
                'minimum' => 25.0,
                'maximum' => 30.0,
                'tone' => 'danger',
                'message' => 'IMC compris entre 25,0 et 29,9. À interpréter avec les autres données cliniques.',
            ],
            [
                'code' => 'OBESITY_I',
                'label' => 'Obésité — classe I',
                'minimum' => 30.0,
                'maximum' => 35.0,
                'tone' => 'danger',
                'message' => 'IMC compris entre 30,0 et 34,9. Une évaluation clinique est recommandée.',
            ],
            [
                'code' => 'OBESITY_II',
                'label' => 'Obésité — classe II',
                'minimum' => 35.0,
                'maximum' => 40.0,
                'tone' => 'danger',
                'message' => 'IMC compris entre 35,0 et 39,9. Une évaluation clinique est recommandée.',
            ],
            [
                'code' => 'OBESITY_III',
                'label' => 'Obésité — classe III',
                'minimum' => 40.0,
                'maximum' => null,
                'tone' => 'danger',
                'message' => 'IMC supérieur ou égal à 40. Une évaluation clinique est recommandée.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pediatricReview(): array
    {
        return [
            'code' => 'PEDIATRIC_REVIEW',
            'label' => 'Interprétation pédiatrique requise',
            'tone' => 'info',
            'message' => 'Avant 20 ans, l’IMC doit être interprété selon l’âge et le sexe avec les courbes de croissance adaptées.',
        ];
    }

    /** @return array<string, mixed> */
    private function ageUnknown(): array
    {
        return [
            'code' => 'AGE_REQUIRED',
            'label' => 'Âge requis pour interpréter l’IMC',
            'tone' => 'info',
            'message' => 'L’IMC est calculé, mais sa catégorie ne peut pas être déterminée sans l’âge du patient.',
        ];
    }
}
