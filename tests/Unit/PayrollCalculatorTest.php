<?php

namespace Tests\Unit;

use App\Enums\EmployeeRemunerationType;
use App\Models\PayrollSetting;
use App\Services\Payroll\PayrollCalculator;
use PHPUnit\Framework\TestCase;

/** ADR-233 — retenues légales de la paie : cotisations plafonnées, IRSA par tranches, minimum, enfants. */
class PayrollCalculatorTest extends TestCase
{
    private function rules(array $overrides = []): array
    {
        return [...(new PayrollSetting(PayrollSetting::PROPOSAL))->snapshot(), 'legal_deductions_enabled' => true, ...$overrides];
    }

    private function compute(int $grossAr, int $children = 0, array $overrides = [], ?EmployeeRemunerationType $type = EmployeeRemunerationType::Salary): array
    {
        return (new PayrollCalculator)->compute($grossAr * 100, $type, $children, $this->rules($overrides));
    }

    public function test_a_salary_of_one_million_pays_cnaps_health_and_progressive_irsa(): void
    {
        $result = $this->compute(1_000_000);

        $this->assertSame(1_000_000, $result['cnaps']);       // 10 000 Ar
        $this->assertSame(1_000_000, $result['health']);      // 10 000 Ar
        $this->assertSame(98_000_000, $result['taxable']);    // 980 000 Ar
        // 2 500 + 10 000 + 15 000 + 76 000 = 103 500 Ar
        $this->assertSame(10_350_000, $result['irsa']);
        $this->assertSame(13_000_000, $result['employer_cnaps']);
        $this->assertSame(5_000_000, $result['employer_health']);
    }

    public function test_each_child_reduces_the_irsa(): void
    {
        $result = $this->compute(1_000_000, children: 2);

        $this->assertSame(400_000, $result['child_reduction']);
        $this->assertSame(9_950_000, $result['irsa']);
    }

    public function test_the_minimum_applies_above_the_free_bracket_and_never_below_it(): void
    {
        $this->assertSame(300_000, $this->compute(360_000)['irsa'], '140 Ar calculés, 3 000 Ar minimum');
        $this->assertSame(300_000, $this->compute(400_000, children: 5)['irsa'], 'la réduction ne passe pas sous le minimum');
        $this->assertSame(0, $this->compute(350_000)['irsa'], 'sous le seuil : aucun IRSA, pas de minimum');
    }

    public function test_ceilings_cap_the_contribution_base(): void
    {
        $result = $this->compute(1_000_000, overrides: ['cnaps_ceiling' => '500000.00']);

        $this->assertSame(500_000, $result['cnaps']);
        $this->assertSame(6_500_000, $result['employer_cnaps']);
        $this->assertSame(1_000_000, $result['health'], 'le plafond CNAPS ne touche pas l’organisme médical');
    }

    public function test_the_taxable_base_can_be_rounded_down(): void
    {
        $this->assertSame(35_270_000, $this->compute(359_999, overrides: ['irsa_base_rounding' => 100])['taxable']);
    }

    public function test_nothing_is_withheld_when_disabled_unpaid_or_for_an_internship_allowance(): void
    {
        $this->assertFalse($this->compute(1_000_000, overrides: ['legal_deductions_enabled' => false])['applies']);
        $this->assertFalse($this->compute(1_000_000, type: EmployeeRemunerationType::Unpaid)['applies']);
        $this->assertFalse($this->compute(1_000_000, type: EmployeeRemunerationType::Allowance)['applies']);
        $this->assertTrue($this->compute(1_000_000, overrides: ['allowance_subject' => true], type: EmployeeRemunerationType::Allowance)['applies']);
    }

    public function test_lines_are_negative_and_name_the_rate(): void
    {
        $calculator = new PayrollCalculator;
        $rules = $this->rules();
        $lines = $calculator->lines($calculator->compute(100_000_000, EmployeeRemunerationType::Salary, 0, $rules), $rules);

        $this->assertSame(['CNAPS', 'HEALTH', 'IRSA'], array_column($lines, 'kind'));
        $this->assertSame('-10000.00', $lines[0]['amount']);
        $this->assertSame('CNAPS — 1 %', $lines[0]['label']);
        $this->assertStringContainsString('base imposable 980 000 Ar', $lines[2]['label']);
    }
}
