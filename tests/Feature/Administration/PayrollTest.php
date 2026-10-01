<?php

namespace Tests\Feature\Administration;

use App\Enums\AdvantageEntryStatus;
use App\Enums\SalaryPaymentStatus;
use App\Models\AdvantageEntry;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\SuperAdmin\SiteHrGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-227 — avantages saisis pour les médecins (module Bonus) et paie du mois : salaire de
 * base déclaré + avantages = montant à verser ; marquer payé fige et empêche un second
 * paiement ; un avantage payé ne se modifie plus.
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private const HR = [
        'bonus_categories.view', 'bonus_awards.view',
        'advantage_entries.view', 'advantage_entries.create', 'advantage_entries.update', 'advantage_entries.delete',
        'salary_payments.view', 'salary_payments.pay', 'salary_payments.cancel', 'salary_payments.export',
        'salary_settings.view', 'salary_settings.update',
    ];

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->hr = $this->user(self::HR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hr_enters_several_advantages_for_doctors_and_sees_them_counted(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true);
        $other = $this->employee('EMP-D2', benefits: true);

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/saisis', ['lines' => [
            ['employee_uuid' => $doctor->uuid, 'amount' => '150 000,50', 'reason' => 'ECHO', 'period' => '2026-09'],
            ['employee_uuid' => $doctor->uuid, 'amount' => '20000', 'reason' => 'Garde', 'period' => '2026-09'],
            ['employee_uuid' => $other->uuid, 'amount' => '5000', 'reason' => 'ECG', 'period' => '2026-09'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame(3, AdvantageEntry::query()->count());
        $this->assertSame('150000.50', (string) AdvantageEntry::query()->where('reason', 'ECHO')->value('amount'));

        $this->actingAs($this->hr)->get('/administration/bonus?onglet=saisis&mois=2026-09')->tap(fn ($r) => $r->status() === 500 ? dump($r->exception?->getMessage()) : null)
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'entries')
                ->where('entries.summary.count', 3)
                ->where('entries.summary.people', 2)
                ->has('entries.doctors', 2)
                ->etc());
    }

    public function test_articles_already_entered_are_proposed_at_their_last_amount(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true);
        AdvantageEntry::query()->create(['employee_id' => $doctor->id, 'period' => '2026-08-01', 'amount' => '70000', 'reason' => 'AUTO CHIR', 'status' => AdvantageEntryStatus::Pending, 'created_by' => $this->hr->id]);
        AdvantageEntry::query()->create(['employee_id' => $doctor->id, 'period' => '2026-08-01', 'amount' => '40000', 'reason' => 'Écho', 'status' => AdvantageEntryStatus::Pending, 'created_by' => $this->hr->id]);
        AdvantageEntry::query()->create(['employee_id' => $doctor->id, 'period' => '2026-08-01', 'amount' => '50000', 'reason' => 'ECHO', 'status' => AdvantageEntryStatus::Pending, 'created_by' => $this->hr->id]);

        $this->actingAs($this->hr)->get('/administration/bonus?onglet=saisis&mois=2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.articles', 2)
                ->where('entries.articles.0', ['label' => 'AUTO CHIR', 'amount' => '70000.00'])
                ->where('entries.articles.1', ['label' => 'ECHO', 'amount' => '50000.00'])
                ->etc());
    }

    public function test_the_benefits_box_decides_and_the_job_title_is_only_the_default(): void
    {
        $nurse = $this->employee('EMP-A3', benefits: null);
        $this->assertFalse($nurse->grantsBenefits(), 'sans case cochée ni fonction qui ouvre droit : fermé');

        $nurse->forceFill(['benefits_enabled' => true])->save();
        $this->assertTrue($nurse->refresh()->grantsBenefits());
    }

    public function test_the_removed_act_advantage_routes_are_gone(): void
    {
        $this->actingAs($this->hr)->post('/administration/bonus/avantages/articles', ['name' => 'ECHO'])->assertNotFound();
        $this->actingAs($this->hr)->get('/administration/bonus?onglet=avantages')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'entries')->missing('advantages')->missing('advantageArticles')->etc());
    }

    public function test_entries_are_refused_without_amount_or_reason_or_for_closed_benefits_all_or_nothing(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true);
        $closed = $this->employee('EMP-X1', benefits: false);

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/saisis', ['lines' => [
            ['employee_uuid' => $doctor->uuid, 'amount' => '0', 'reason' => 'ECHO', 'period' => '2026-09'],
        ]])->assertSessionHasErrors('lines.0.amount');

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/saisis', ['lines' => [
            ['employee_uuid' => $doctor->uuid, 'amount' => '1000', 'reason' => '', 'period' => '2026-09'],
        ]])->assertSessionHasErrors('lines.0.reason');

        $this->actingAs($this->hr)->post('/administration/bonus/avantages/saisis', ['lines' => [
            ['employee_uuid' => $doctor->uuid, 'amount' => '1000', 'reason' => 'ECHO', 'period' => '2026-09'],
            ['employee_uuid' => $closed->uuid, 'amount' => '1000', 'reason' => 'ECHO', 'period' => '2026-09'],
        ]])->assertSessionHasErrors('lines.1.employee_uuid');

        $this->assertSame(0, AdvantageEntry::query()->count());
    }

    public function test_payroll_sums_base_salary_and_advantages_then_pay_freezes_and_prevents_double_payment(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true, salary: 800000);
        $entry = $this->entry($doctor, 150000, 'ECHO');
        $this->entry($doctor, 20000, 'Garde', '2026-08');

        $this->actingAs($this->hr)->get('/administration/paie?mois=2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Payroll/Index')
                ->has('board.rows', 1, fn (Assert $row) => $row
                    ->where('uuid', $doctor->uuid)
                    ->where('base_amount', '800000.00')
                    ->where('advantages_amount', '150000.00')
                    ->where('total', '950000.00')
                    ->where('payable', true)
                    ->etc()));

        $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $doctor->uuid, 'mois' => '2026-09', 'note' => 'Virement'])
            ->assertSessionHasNoErrors();

        $payment = SalaryPayment::query()->sole();
        $this->assertSame('950000.00', (string) $payment->total_amount);
        $this->assertSame(AdvantageEntryStatus::Paid, $entry->refresh()->status);
        $this->assertSame($payment->id, $entry->salary_payment_id);

        // Pas de second paiement, pas de modification d'un avantage payé, pas de nouvel avantage sur ce mois.
        $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $doctor->uuid, 'mois' => '2026-09'])
            ->assertSessionHasErrors('period');
        $this->actingAs($this->hr)->put("/administration/bonus/avantages/saisis/{$entry->uuid}", ['amount' => '1', 'reason' => 'X'])
            ->assertSessionHasErrors('entry');
        $this->actingAs($this->hr)->delete("/administration/bonus/avantages/saisis/{$entry->uuid}")
            ->assertSessionHasErrors('entry');
        $this->actingAs($this->hr)->post('/administration/bonus/avantages/saisis', ['lines' => [
            ['employee_uuid' => $doctor->uuid, 'amount' => '1000', 'reason' => 'ECHO', 'period' => '2026-09'],
        ]])->assertSessionHasErrors('lines.0.period');

        // L'avantage d'un autre mois n'a pas été payé.
        $this->assertSame(AdvantageEntryStatus::Pending, AdvantageEntry::query()->where('reason', 'Garde')->sole()->status);
    }

    public function test_cancelling_a_payment_puts_its_advantages_back_to_pending(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true, salary: 500000);
        $entry = $this->entry($doctor, 10000, 'ECG');

        $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $doctor->uuid, 'mois' => '2026-09']);
        $payment = SalaryPayment::query()->sole();

        $this->actingAs($this->hr)->post("/administration/paie/{$payment->uuid}/annuler", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->hr)->post("/administration/paie/{$payment->uuid}/annuler", ['reason' => 'Erreur de montant'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SalaryPaymentStatus::Cancelled, $payment->refresh()->status);
        $this->assertSame(AdvantageEntryStatus::Pending, $entry->refresh()->status);
        $this->assertNull($entry->salary_payment_id);

        // Modifiable de nouveau, puis repayable.
        $this->actingAs($this->hr)->put("/administration/bonus/avantages/saisis/{$entry->uuid}", ['amount' => '12000', 'reason' => 'ECG'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $doctor->uuid, 'mois' => '2026-09'])
            ->assertSessionHasNoErrors();
        $this->assertSame('512000.00', (string) SalaryPayment::query()->where('status', SalaryPaymentStatus::Paid)->sole()->total_amount);
    }

    public function test_settings_are_proposed_disabled_then_saved_and_validated(): void
    {
        $this->actingAs($this->hr)->get('/administration/paie/parametres')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Payroll/Settings')
                ->where('configured', false)
                ->where('settings.legal_deductions_enabled', false)
                ->where('settings.irsa_brackets.4.up_to', null)
                ->etc());

        $payload = $this->settingsPayload();
        $payload['irsa_brackets'][1]['up_to'] = '300 000';
        $this->actingAs($this->hr)->put('/administration/paie/parametres', $payload)
            ->assertSessionHasErrors('irsa_brackets.1.up_to');

        $this->actingAs($this->hr)->put('/administration/paie/parametres', $this->settingsPayload(['cnaps_ceiling' => '2 101 440']))
            ->assertSessionHasNoErrors();
        $settings = PayrollSetting::current();
        $this->assertTrue($settings->legal_deductions_enabled);
        $this->assertSame('2101440.00', (string) $settings->cnaps_ceiling);
    }

    public function test_simulation_computes_without_saving(): void
    {
        $this->actingAs($this->hr)->postJson('/administration/paie/parametres/simulation', [...$this->settingsPayload(['legal_deductions_enabled' => false]), 'gross' => 1000000, 'children' => 0])
            ->assertOk()
            ->assertJson(['cnaps' => '10000.00', 'health' => '10000.00', 'irsa' => '103500.00', 'net' => '876500.00', 'employer' => '180000.00', 'cost' => '1180000.00']);
        $this->assertFalse(PayrollSetting::query()->exists());
    }

    public function test_paying_withholds_legal_deductions_and_freezes_rules_and_payment_mode(): void
    {
        $this->actingAs($this->hr)->put('/administration/paie/parametres', $this->settingsPayload())->assertSessionHasNoErrors();
        $employee = $this->employee('EMP-S1', benefits: null, salary: 1000000);
        $employee->forceFill(['salary_payment_mode' => 'CASH'])->save();

        $this->actingAs($this->hr)->get('/administration/paie?mois=2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->where('board.rows.0.legal_amount', '123500.00')
                ->where('board.rows.0.total', '876500.00')
                ->where('board.rows.0.employer_amount', '180000.00')
                ->where('board.rows.0.payment_mode.label', 'Espèces')
                ->where('board.settings.legal_enabled', true)
                ->etc());

        $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $employee->uuid, 'mois' => '2026-09'])
            ->assertSessionHasNoErrors();
        $payment = SalaryPayment::query()->sole();
        $this->assertSame('1000000.00', (string) $payment->total_amount);
        $this->assertSame('123500.00', (string) $payment->legal_deductions_amount);
        $this->assertSame('123500.00', (string) $payment->deductions_amount);
        $this->assertSame('876500.00', $payment->netAmount());
        $this->assertSame('180000.00', (string) $payment->employer_charges_amount);
        $this->assertSame('CASH', $payment->payment_mode);
        $this->assertSame('1.00', $payment->payroll_snapshot['rules']['cnaps_employee_rate']);

        // Changer les paramètres ensuite ne réécrit pas une paie payée.
        $this->actingAs($this->hr)->put('/administration/paie/parametres', $this->settingsPayload(['cnaps_employee_rate' => '5']))->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->get('/administration/paie?mois=2026-09')
            ->assertInertia(fn (Assert $page) => $page->where('board.rows.0.total', '876500.00')->etc());
    }

    public function test_a_selection_is_paid_each_on_its_own_with_a_report(): void
    {
        $first = $this->employee('EMP-B1', benefits: null, salary: 500000);
        $second = $this->employee('EMP-B2', benefits: null, salary: 400000);
        $nothing = $this->employee('EMP-B3', benefits: null);

        $this->actingAs($this->hr)->post('/administration/paie/payer-lot', ['employee_uuids' => [$first->uuid, $second->uuid, $nothing->uuid], 'mois' => '2026-09'])
            ->assertSessionHas('bulk_report', fn (array $report) => $report['done'] === 2 && count($report['failed']) === 1 && $report['amount'] === '900000.00');
        $this->assertSame(2, SalaryPayment::query()->count());
    }

    public function test_payslips_and_exports(): void
    {
        $employee = $this->employee('EMP-P1', benefits: null, salary: 500000);

        $this->actingAs($this->hr)->get("/administration/paie/bulletins?mois=2026-09&uuids[]={$employee->uuid}")
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Payroll/Payslips')->has('rows', 1)->where('rows.0.employee_number', 'EMP-P1')->etc());

        $this->actingAs($this->hr)->get('/administration/paie/export?mois=2026-09')->assertOk()->assertDownload('journal-paie-2026-09.xlsx');
        $this->actingAs($this->hr)->get('/administration/paie/export?mois=2026-09&type=virements')->assertOk()->assertDownload('virements-paie-2026-09.xlsx');

        $reader = $this->user(['salary_payments.view'], 'RECEPTION');
        $this->actingAs($reader)->get('/administration/paie/export?mois=2026-09')->assertForbidden();
        $this->actingAs($reader)->put('/administration/paie/parametres', $this->settingsPayload())->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        return [...(new PayrollSetting(PayrollSetting::PROPOSAL))->snapshot(), 'legal_deductions_enabled' => true, ...$overrides];
    }

    public function test_a_future_month_is_not_payable(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true, salary: 500000);

        $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $doctor->uuid, 'mois' => '2026-10'])
            ->assertSessionHasErrors('period');
    }

    public function test_permissions_guard_each_gesture(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true, salary: 500000);
        $reader = $this->user(['advantage_entries.view', 'salary_payments.view', 'bonus_categories.view'], 'RECEPTION');

        $this->actingAs($reader)->get('/administration/paie')->assertOk();
        $this->actingAs($reader)->post('/administration/paie/payer', ['employee_uuid' => $doctor->uuid, 'mois' => '2026-09'])->assertForbidden();
        $this->actingAs($reader)->post('/administration/bonus/avantages/saisis', ['lines' => [
            ['employee_uuid' => $doctor->uuid, 'amount' => '1000', 'reason' => 'ECHO', 'period' => '2026-09'],
        ]])->assertForbidden();

        $nobody = $this->user([], 'SUPPORT');
        $this->actingAs($nobody)->get('/administration/paie')->assertForbidden();
    }

    public function test_the_portal_relays_the_payroll_screen(): void
    {
        $this->assertTrue(app(SiteHrGateway::class)->isHrScreen('Administration/Payroll/Index'));
    }

    private function user(array $permissions, string $code = 'ADMINISTRATION'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);

        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function employee(string $number, ?bool $benefits, ?int $salary = null): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number, 'first_name' => 'Doc', 'last_name' => $number, 'sex' => 'M', 'active' => true,
            'benefits_enabled' => $benefits,
            'remuneration_type' => $salary ? 'SALARY' : null,
            'remuneration_amount' => $salary,
        ]);
    }

    private function entry(Employee $employee, int $amount, string $reason, string $month = '2026-09'): AdvantageEntry
    {
        return AdvantageEntry::query()->create([
            'employee_id' => $employee->id, 'period' => "{$month}-01", 'amount' => $amount, 'reason' => $reason,
            'status' => AdvantageEntryStatus::Pending, 'created_by' => $this->hr->id,
        ]);
    }
}
