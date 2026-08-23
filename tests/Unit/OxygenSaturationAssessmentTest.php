<?php

namespace Tests\Unit;

use App\Support\OxygenSaturationAssessment;
use PHPUnit\Framework\TestCase;

class OxygenSaturationAssessmentTest extends TestCase
{
    public function test_it_warns_between_ninety_three_and_ninety_four_percent(): void
    {
        $classifier = new OxygenSaturationAssessment;

        $this->assertSame('LOW', $classifier->classify(94)['code']);
        $this->assertSame('warning', $classifier->classify(93)['tone']);
    }

    public function test_it_uses_a_danger_alert_at_ninety_two_percent_or_less(): void
    {
        $classifier = new OxygenSaturationAssessment;

        $this->assertSame('VERY_LOW', $classifier->classify(92)['code']);
        $this->assertSame('danger', $classifier->classify(88)['tone']);
    }

    public function test_it_returns_no_alert_for_the_usual_range_or_an_empty_value(): void
    {
        $classifier = new OxygenSaturationAssessment;

        $this->assertNull($classifier->classify(95));
        $this->assertNull($classifier->classify(100));
        $this->assertNull($classifier->classify(null));
        $this->assertNull($classifier->classify(''));
    }
}
