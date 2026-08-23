<?php

namespace Tests\Unit;

use App\Support\BloodPressureAssessment;
use PHPUnit\Framework\TestCase;

class BloodPressureAssessmentTest extends TestCase
{
    public function test_it_warns_for_low_and_elevated_readings(): void
    {
        $classifier = new BloodPressureAssessment;

        $this->assertSame('LOW', $classifier->classify(89, 60)['code']);
        $this->assertSame('LOW', $classifier->classify(100, 59)['code']);
        $this->assertSame('HIGH_STAGE_1', $classifier->classify(130, 79)['code']);
        $this->assertSame('HIGH_STAGE_1', $classifier->classify(120, 80)['code']);
        $this->assertSame('HIGH_STAGE_2', $classifier->classify(140, 80)['code']);
        $this->assertSame('HIGH_STAGE_2', $classifier->classify(130, 90)['code']);
    }

    public function test_it_uses_danger_only_above_the_severe_threshold(): void
    {
        $classifier = new BloodPressureAssessment;

        $this->assertSame('HIGH_STAGE_2', $classifier->classify(180, 120)['code']);
        $this->assertSame('SEVERE_HIGH', $classifier->classify(181, 100)['code']);
        $this->assertSame('danger', $classifier->classify(150, 121)['tone']);
    }

    public function test_it_returns_no_alert_for_normal_or_invalid_pairs(): void
    {
        $classifier = new BloodPressureAssessment;

        $this->assertNull($classifier->classify(119, 79));
        $this->assertNull($classifier->classify(17, 12));
        $this->assertNull($classifier->classify(80, 120));
        $this->assertNull($classifier->classify(null, null));
    }
}
