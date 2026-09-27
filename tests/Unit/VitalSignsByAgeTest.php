<?php

namespace Tests\Unit;

use App\Support\BloodPressureAssessment;
use App\Support\HeartRateAssessment;
use App\Support\TemperatureAssessment;
use App\Support\VitalSignAgeReference;
use PHPUnit\Framework\TestCase;

/**
 * ADR-125 — les constantes se lisent selon l'âge du patient : la même valeur peut
 * être normale à 8 mois, trop rapide à 12 ans, ou critique à 4 ans.
 */
class VitalSignsByAgeTest extends TestCase
{
    public function test_a_heart_rate_is_read_against_the_range_of_the_age(): void
    {
        $hr = new HeartRateAssessment;

        // 78 bpm : normal à 12 ans et à l'âge adulte, bas à 4 ans (80–120).
        $this->assertNull($hr->classify(78, 12));
        $this->assertNull($hr->classify(78, 30));
        $this->assertSame('LOW_FOR_AGE', $hr->classify(78, 4)['code']);
        $this->assertSame('warning', $hr->classify(78, 4)['tone']);

        // 150 bpm : normal à 8 mois (100–180), très élevé à 30 ans.
        $this->assertNull($hr->classify(150, 0));
        $this->assertSame('MARKEDLY_HIGH', $hr->classify(150, 30)['code']);
        $this->assertSame('HIGH', $hr->classify(125, 6)['code']);
        $this->assertSame('MARKEDLY_HIGH', $hr->classify(150, 6)['code']);
    }

    public function test_an_adult_fast_heart_rate_is_flagged_and_an_unknown_age_is_not_guessed(): void
    {
        $hr = new HeartRateAssessment;

        $this->assertNull($hr->classify(100, 40));
        $this->assertSame('HIGH', $hr->classify(110, 40)['code']);
        $this->assertSame('MARKEDLY_HIGH', $hr->classify(130, 40)['code']);
        // Sans âge, pas de lecture d'un rythme rapide : on ne devine pas une plage.
        $this->assertNull($hr->classify(150, null));
    }

    public function test_a_minor_under_60_is_always_flagged_and_a_marked_gap_is_danger(): void
    {
        $hr = new HeartRateAssessment;

        $this->assertSame('PEDIATRIC_LOW', $hr->classify(55, 15)['code']);
        $this->assertSame('danger', $hr->classify(55, 15)['tone']);
        $this->assertSame('MARKEDLY_LOW_FOR_AGE', $hr->classify(66, 4)['code']);
        $this->assertSame('danger', $hr->classify(66, 4)['tone']);
    }

    public function test_a_childs_hypotension_follows_the_pals_definition(): void
    {
        $bp = new BloodPressureAssessment;

        // 1–10 ans : 70 + 2 × âge → 78 à 4 ans.
        $this->assertSame(78, VitalSignAgeReference::hypotensionSystolicBelow(4));
        $this->assertSame('PEDIATRIC_HYPOTENSION', $bp->classify(75, 50, 4)['code']);
        $this->assertNull($bp->classify(85, 50, 4));
        // Le même 85/50 est une TA basse chez l'adulte.
        $this->assertSame('LOW', $bp->classify(85, 50, 40)['code']);
        $this->assertSame(90, VitalSignAgeReference::hypotensionSystolicBelow(14));
    }

    public function test_a_childs_high_pressure_is_not_classified_as_an_adult_would(): void
    {
        $bp = new BloodPressureAssessment;

        // 170/120 à 4 ans : critique (le cas qui a motivé cette décision).
        $this->assertSame('CHILD_VERY_HIGH', $bp->classify(170, 110, 4)['code']);
        $this->assertSame('danger', $bp->classify(170, 110, 4)['tone']);
        // 125/70 à 6 ans : à comparer aux tables ; jamais « étape 1 » d'adulte.
        $this->assertSame('CHILD_HIGH', $bp->classify(125, 70, 6)['code']);
        $this->assertNull($bp->classify(105, 65, 6));
        // À partir de 13 ans, les étapes de l'adulte s'appliquent.
        $this->assertSame('HIGH_STAGE_1', $bp->classify(132, 78, 14)['code']);
        // Sans âge : lecture d'adulte, comme avant.
        $this->assertSame('HIGH_STAGE_1', $bp->classify(132, 78, null)['code']);
        // Le seuil sévère de l'adulte reste critique à tout âge.
        $this->assertSame('SEVERE_HIGH', $bp->classify(190, 100, 8)['code']);
    }

    public function test_an_infant_is_stricter_on_temperature(): void
    {
        $t = new TemperatureAssessment;

        $this->assertSame('INFANT_FEVER', $t->classify(38.0, 0)['code']);
        $this->assertSame('danger', $t->classify(38.0, 0)['tone']);
        $this->assertSame('FEVER', $t->classify(38.0, 30)['code']);
        $this->assertSame('INFANT_LOW', $t->classify(36.2, 0)['code']);
        $this->assertSame('INFANT_VERY_LOW', $t->classify(35.8, 0)['code']);
        $this->assertNull($t->classify(36.8, 0));
        // 36,2 °C n'est pas une alerte chez un adulte.
        $this->assertNotNull($t->classify(35.8, 30));
        $this->assertNull($t->classify(36.2, 30));
    }

    public function test_the_references_and_bands_are_the_single_source(): void
    {
        $this->assertSame([100, 180], VitalSignAgeReference::heartRateRange(0));
        $this->assertSame([80, 120], VitalSignAgeReference::heartRateRange(4));
        $this->assertSame([60, 100], VitalSignAgeReference::heartRateRange(30));
        $this->assertSame('nourrisson', VitalSignAgeReference::band(0));
        $this->assertSame('adolescent', VitalSignAgeReference::band(15));
        $this->assertSame(30, VitalSignAgeReference::plausibility(4)['weight_max']);
        $this->assertSame(130, VitalSignAgeReference::plausibility(4)['height_max']);
        $this->assertSame(4, VitalSignAgeReference::plausibility(4)['patient_age']);
        $this->assertSame(10, VitalSignAgeReference::plausibility(4)['substance_unlikely_below']);
        $this->assertSame(110, VitalSignAgeReference::plausibility(4)['very_old_from']);
        $this->assertSame(300, VitalSignAgeReference::plausibility(null)['weight_max']);
        $this->assertNull(VitalSignAgeReference::plausibility(null)['patient_age']);

        $reference = (new HeartRateAssessment)->reference(4);
        $this->assertSame(['min' => 80, 'max' => 120], $reference['age_range']);
        $this->assertNull((new HeartRateAssessment)->reference(null)['age_range']);
    }
}
