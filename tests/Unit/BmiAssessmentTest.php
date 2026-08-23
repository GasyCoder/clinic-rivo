<?php

namespace Tests\Unit;

use App\Support\BmiAssessment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BmiAssessmentTest extends TestCase
{
    public static function adultBands(): array
    {
        return [
            ['18.49', 'UNDERWEIGHT'],
            ['18.50', 'NORMAL'],
            ['24.99', 'NORMAL'],
            ['25.00', 'OVERWEIGHT'],
            ['29.99', 'OVERWEIGHT'],
            ['30.00', 'OBESITY_I'],
            ['34.99', 'OBESITY_I'],
            ['35.00', 'OBESITY_II'],
            ['39.99', 'OBESITY_II'],
            ['40.00', 'OBESITY_III'],
        ];
    }

    #[DataProvider('adultBands')]
    public function test_it_classifies_adult_bmi_at_each_boundary(string $bmi, string $expectedCode): void
    {
        $assessment = (new BmiAssessment)->classify($bmi, 35);

        $this->assertSame($expectedCode, $assessment['code']);
    }

    public function test_it_never_applies_adult_bands_to_a_patient_under_twenty(): void
    {
        $assessment = (new BmiAssessment)->classify('31.50', 19);

        $this->assertSame('PEDIATRIC_REVIEW', $assessment['code']);
        $this->assertSame('info', $assessment['tone']);
    }

    public function test_overweight_uses_the_red_alert_tone(): void
    {
        $assessment = (new BmiAssessment)->classify('27.50', 35);

        $this->assertSame('OVERWEIGHT', $assessment['code']);
        $this->assertSame('danger', $assessment['tone']);
    }

    public function test_it_requests_age_instead_of_guessing_a_category(): void
    {
        $assessment = (new BmiAssessment)->classify('22.00', null);

        $this->assertSame('AGE_REQUIRED', $assessment['code']);
    }

    public function test_it_returns_no_assessment_without_a_valid_bmi(): void
    {
        $classifier = new BmiAssessment;

        $this->assertNull($classifier->classify(null, 40));
        $this->assertNull($classifier->classify('0', 40));
        $this->assertNull($classifier->classify('invalid', 40));
    }
}
