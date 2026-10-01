<?php

namespace Tests\Feature\Administration;

use App\Enums\AdvantageEntryStatus;
use App\Enums\AdvantageSource;
use App\Models\AdvantageArticle;
use App\Enums\SalaryPaymentStatus;
use App\Models\AdvantageEntry;
use App\Models\Employee;
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
        'salary_payments.view', 'salary_payments.pay', 'salary_payments.cancel',
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

    public function test_known_articles_are_proposed_with_their_amount(): void
    {
        $doctor = $this->employee('EMP-D1', benefits: true);
        AdvantageArticle::query()->create(['name' => 'AUTO CHIR', 'source' => AdvantageSource::Performed, 'unit_price' => '70000', 'active' => true]);
        AdvantageArticle::query()->create(['name' => 'Retiré', 'source' => AdvantageSource::Performed, 'unit_price' => '1000', 'active' => false]);
        AdvantageEntry::query()->create(['employee_id' => $doctor->id, 'period' => '2026-08-01', 'amount' => '40000', 'reason' => 'Écho', 'status' => AdvantageEntryStatus::Pending, 'created_by' => $this->hr->id]);
        AdvantageEntry::query()->create(['employee_id' => $doctor->id, 'period' => '2026-08-01', 'amount' => '50000', 'reason' => 'ECHO', 'status' => AdvantageEntryStatus::Pending, 'created_by' => $this->hr->id]);

        $this->actingAs($this->hr)->get('/administration/bonus?onglet=saisis&mois=2026-09')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.articles', 2)
                ->where('entries.articles.0', ['label' => 'AUTO CHIR', 'amount' => '70000.00', 'kind' => 'article'])
                ->where('entries.articles.1', ['label' => 'ECHO', 'amount' => '50000.00', 'kind' => 'history'])
                ->etc());
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
