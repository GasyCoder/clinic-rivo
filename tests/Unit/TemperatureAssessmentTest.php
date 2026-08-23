<?php

namespace Tests\Unit;

use App\Support\TemperatureAssessment;
use PHPUnit\Framework\TestCase;

class TemperatureAssessmentTest extends TestCase
{
    public function test_it_warns_for_a_low_temperature_or_fever(): void
    {
        $classifier = new TemperatureAssessment;

        $this->assertSame('LOW', $classifier->classify('35.9')['code']);
        $this->assertSame('FEVER', $classifier->classify('38.0')['code']);
    }

    public function test_it_uses_danger_for_possible_hypothermia_or_a_very_high_temperature(): void
    {
        $classifier = new TemperatureAssessment;

        $this->assertSame('VERY_LOW', $classifier->classify('34.9')['code']);
        $this->assertSame('danger', $classifier->classify('34.9')['tone']);
        $this->assertSame('VERY_HIGH', $classifier->classify('40.0')['code']);
        $this->assertSame('danger', $classifier->classify('40.0')['tone']);
    }

    public function test_it_returns_no_alert_in_the_intermediate_range_or_without_a_value(): void
    {
        $classifier = new TemperatureAssessment;

        $this->assertNull($classifier->classify('36.0'));
        $this->assertNull($classifier->classify('37.9'));
        $this->assertNull($classifier->classify(null));
        $this->assertNull($classifier->classify(''));
    }
}
