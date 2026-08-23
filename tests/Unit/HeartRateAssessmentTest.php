<?php

namespace Tests\Unit;

use App\Support\HeartRateAssessment;
use PHPUnit\Framework\TestCase;

class HeartRateAssessmentTest extends TestCase
{
    public function test_it_warns_for_an_adult_heart_rate_below_sixty(): void
    {
        $assessment = (new HeartRateAssessment)->classify(55, 35);

        $this->assertSame('LOW', $assessment['code']);
        $this->assertSame('warning', $assessment['tone']);
    }

    public function test_it_uses_a_danger_alert_below_fifty_for_an_adult(): void
    {
        $assessment = (new HeartRateAssessment)->classify(49, 35);

        $this->assertSame('MARKEDLY_LOW', $assessment['code']);
        $this->assertSame('danger', $assessment['tone']);
    }

    public function test_it_uses_a_distinct_danger_alert_for_a_minor_below_sixty(): void
    {
        $assessment = (new HeartRateAssessment)->classify(55, 12);

        $this->assertSame('PEDIATRIC_LOW', $assessment['code']);
        $this->assertSame('danger', $assessment['tone']);
    }

    public function test_it_requests_age_context_without_hiding_the_alert(): void
    {
        $assessment = (new HeartRateAssessment)->classify(55, null);

        $this->assertSame('LOW_AGE_UNKNOWN', $assessment['code']);
        $this->assertSame('warning', $assessment['tone']);
    }

    public function test_it_returns_no_alert_at_sixty_or_without_a_valid_measurement(): void
    {
        $classifier = new HeartRateAssessment;

        $this->assertNull($classifier->classify(60, 35));
        $this->assertNull($classifier->classify(null, 35));
        $this->assertNull($classifier->classify('invalid', 35));
    }
}
