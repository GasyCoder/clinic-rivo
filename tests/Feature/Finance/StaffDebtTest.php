<?php

namespace Tests\Feature\Administration;

use App\Enums\StaffDebtStatus;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalaryPayment;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\StaffDebtUpdated;
use App\Services\SuperAdmin\SiteHrGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-228 — les dettes du personnel : demandées par l'employé depuis son compte,
 * décidées par le DG, versées hors RIVO par le RH, remboursées par retenue sur la paie
 * du mois (jamais plus que le brut) ou en espèces à la Caisse.
 */
class StaffDebtTest extends TestCase
{
    use RefreshDatabase;

    private const DG = ['staff_debts.view', 'staff_debts.decide', 'staff_debts.write_off', 'employees.payroll.view'];

    private const HR = ['staff_debts.view', 'staff_debts.disburse', 'salary_payments.view', 'salary_payments.pay', 'salary_payments.cancel'];

    private User $dg;

    private User $hr;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->dg = $this->user(self::DG, 'DG');
        $this->hr = $this->user(self::HR);
        $this->cashier = $this->user(['staff_debts.collect', 'cash.view'], 'RECEPTION');
        PaymentMethod::query()->create(['code' => 'CASH', 'name' => 'Espèces', 'category' => 'CASH', 'active' => true, 'affects_cash_balance' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_employee_requests_a_debt_from_their_account_and_withdraws_it(): void
    {
        [$user] = $this->staff(salary: 400000);

        $this->actingAs($user)->get('/mes-dettes')->assertInertia(fn (Assert $page) => $page
            ->component('StaffDebts/Mine')->where('space.can_request', true)->where('space.debts', [])->etc());

        $this->actingAs($user)->post('/mes-dettes', [
            'amount' => '300000', 'installment_amount' => '100000', 'first_period' => '2026-10', 'reason' => 'Frais de scolarité',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $debt = StaffDebt::query()->sole();
        $this->assertSame(StaffDebtStatus::Requested, $debt->status);
        $this->assertSame('300000.00', (string) $debt->requested_amount);
        $this->assertStringContainsString('DP-', $debt->number);

        // Une seule demande en attente à la fois.
        $this->actingAs($user)->post('/mes-dettes', [
            'amount' => '10000', 'installment_amount' => '5000', 'first_period' => '2026-10', 'reason' => 'Autre besoin',
        ])->assertSessionHasErrors('employee');

        $this->actingAs($user)->get('/mes-dettes')->assertInertia(fn (Assert $page) => $page
            ->where('space.can_request', false)
            ->where('space.debts.0.requested.plan.count', 3)
            ->where('space.debts.0.requested.plan.last_period', '2026-12')
            ->where('space.debts.0.can.withdraw', true)
            ->etc());

        $this->actingAs($user)->post("/mes-dettes/{$debt->uuid}/retirer")->assertSessionHasNoErrors();
        $this->assertSame(StaffDebtStatus::Cancelled, $debt->fresh()->status);
        $this->assertNull($debt->fresh()->pending_key);
    }

    public function test_a_request_is_refused_without_a_linked_record_or_with_incoherent_terms(): void
    {
        $unlinked = $this->user(['staff_debts.request'], 'NURSE');
        $this->actingAs($unlinked)->post('/mes-dettes', [
            'amount' => '10000', 'installment_amount' => '5000', 'first_period' => '2026-10', 'reason' => 'Besoin urgent',
        ])->assertSessionHasErrors('employee');

        [$user] = $this->staff(salary: 400000);
        $this->actingAs($user)->post('/mes-dettes', [
            'amount' => '10000', 'installment_amount' => '20000', 'first_period' => '2026-10', 'reason' => 'Besoin urgent',
        ])->assertSessionHasErrors('installment_amount');
        $this->actingAs($user)->post('/mes-dettes', [
            'amount' => '10000', 'installment_amount' => '5000', 'first_period' => '2026-08', 'reason' => 'Besoin urgent',
        ])->assertSessionHasErrors('first_period');

        $this->assertSame(0, StaffDebt::query()->count());
    }

    public function test_the_dg_approves_with_adjusted_terms_and_the_employee_and_hr_are_told(): void
    {
        [$user, $employee] = $this->staff(salary: 400000);
        $debt = $this->request($user, 300000, 100000, '2026-10');

        // Sans le droit de décider, le RH ne peut pas accorder.
        $this->actingAs($this->hr)->post("/administration/dettes/{$debt->uuid}/accorder", $this->terms(200000, 50000, '2026-11'))->assertForbidden();

        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/accorder", $this->terms(200000, 50000, '2026-11'))
            ->assertSessionHasNoErrors();

        $debt->refresh();
        $this->assertSame(StaffDebtStatus::Approved, $debt->status);
        $this->assertSame('200000.00', (string) $debt->amount);
        $this->assertSame('2026-11', $debt->first_period->format('Y-m'));
        $this->assertNull($debt->pending_key);

        $this->assertSame(1, UserNotification::query()->for($user)->where('type', StaffDebtUpdated::class)->count());
        $this->assertSame(1, UserNotification::query()->for($this->hr)->where('type', StaffDebtUpdated::class)->count());

        $this->actingAs($this->dg)->get("/administration/dettes/{$debt->uuid}")->assertInertia(fn (Assert $page) => $page
            ->component('Administration/StaffDebts/Show')
            ->where('debt.granted.adjusted', true)
            ->where('debt.granted.plan.count', 4)
            ->where('debt.employee.salary', '400000.00')
            ->where('debt.can.adjust', true)
            ->etc());

        // Le RH, sans le droit de voir les salaires, ne le lit pas.
        $this->actingAs($this->hr)->get("/administration/dettes/{$debt->uuid}")->assertInertia(fn (Assert $page) => $page
            ->where('debt.employee.salary', null)->where('debt.can.disburse', true)->etc());
    }

    public function test_salary_repayment_needs_a_declared_salary_and_a_refusal_needs_a_reason(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->request($user, 100000, 50000, '2026-10');

        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/accorder", $this->terms(100000, 50000, '2026-10'))
            ->assertSessionHasErrors('repayment_mode');
        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/refuser", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/refuser", ['reason' => 'Trop de dettes en cours'])->assertSessionHasNoErrors();

        $this->assertSame(StaffDebtStatus::Refused, $debt->fresh()->status);
        $this->assertSame('Trop de dettes en cours', $debt->fresh()->refusal_reason);
    }

    public function test_hr_marks_it_disbursed_and_the_payroll_retains_the_installment_until_settled(): void
    {
        [$user, $employee] = $this->staff(salary: 400000);
        $debt = $this->approved($user, 250000, 100000, '2026-09');

        $this->actingAs($this->hr)->post("/administration/dettes/{$debt->uuid}/verser", ['disbursed_on' => '2026-09-21', 'disbursement_mode' => 'CASH'])
            ->assertSessionHasErrors('disbursed_on');
        $this->actingAs($this->hr)->post("/administration/dettes/{$debt->uuid}/verser", ['disbursed_on' => '2026-09-20', 'disbursement_mode' => 'BANK', 'reference' => 'VIR-12'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StaffDebtStatus::Active, $debt->fresh()->status);

        $this->actingAs($this->hr)->get('/administration/paie?mois=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('board.rows.0.gross', '400000.00')
            ->where('board.rows.0.deductions_amount', '100000.00')
            ->where('board.rows.0.total', '300000.00')
            ->where('board.rows.0.lines.1.kind', 'DEBT')
            ->where('board.rows.0.lines.1.amount', '-100000.00')
            ->etc());

        foreach (['2026-09', '2026-10', '2026-11'] as $month) {
            Carbon::setTestNow("{$month}-25 10:00:00");
            $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $employee->uuid, 'mois' => $month])->assertSessionHasNoErrors();
        }

        $payments = SalaryPayment::query()->orderBy('period')->get();
        $this->assertSame(['100000.00', '100000.00', '50000.00'], $payments->map(fn ($payment) => (string) $payment->deductions_amount)->all());
        $this->assertSame('350000.00', $payments->last()->netAmount());
        $this->assertSame(StaffDebtStatus::Settled, $debt->fresh()->status);
        $this->assertSame(0, $debt->fresh()->balanceMinor());

        // Annuler la dernière paie : sa retenue n'a pas eu lieu, la dette rouvre.
        $this->actingAs($this->hr)->post("/administration/paie/{$payments->last()->uuid}/annuler", ['reason' => 'Erreur de saisie'])->assertSessionHasNoErrors();
        $this->assertSame(StaffDebtStatus::Active, $debt->fresh()->status);
        $this->assertSame(5000000, $debt->fresh()->balanceMinor());
        $this->assertNotNull(StaffDebtRepayment::query()->where('salary_payment_id', $payments->last()->id)->value('reversed_at'));
    }

    public function test_the_payroll_never_retains_more_than_the_gross_and_waits_for_the_disbursement(): void
    {
        [$user, $employee] = $this->staff(salary: 60000);
        $debt = $this->approved($user, 200000, 100000, '2026-09');

        // Accordée mais pas versée : rien n'est retenu.
        $this->actingAs($this->hr)->get('/administration/paie?mois=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('board.rows.0.deductions_amount', '0.00')->etc());

        $this->disburse($debt);
        $this->actingAs($this->hr)->post('/administration/paie/payer', ['employee_uuid' => $employee->uuid, 'mois' => '2026-09'])->assertSessionHasNoErrors();

        $payment = SalaryPayment::query()->sole();
        $this->assertSame('60000.00', (string) $payment->deductions_amount);
        $this->assertSame('0.00', $payment->netAmount());
        $this->assertStringContainsString('reporté', collect($payment->lines)->firstWhere('kind', 'DEBT')['label']);
        $this->assertSame(14000000, $debt->fresh()->balanceMinor());
    }

    public function test_the_cash_desk_collects_a_repayment_in_its_own_session_and_can_reverse_it(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->approved($user, 30000, 10000, '2026-09', mode: 'CASH');
        $this->disburse($debt);
        $session = $this->openSession($this->cashier);

        $this->actingAs($this->cashier)->post("/cash/staff-debts/{$debt->uuid}/repayments", ['amount' => '40000'])->assertSessionHasErrors('amount');
        $this->actingAs($this->cashier)->post("/cash/staff-debts/{$debt->uuid}/repayments", ['amount' => '30000'])->assertSessionHasNoErrors();

        $repayment = StaffDebtRepayment::query()->sole();
        $this->assertStringContainsString('RD-', $repayment->receipt_number);
        $this->assertSame(StaffDebtStatus::Settled, $debt->fresh()->status);
        $this->assertSame('40000.00', $session->fresh()->computeExpectedClosingAmount());
        $this->assertSame('STAFF_DEBT_REPAYMENT', CashMovement::query()->sole()->type);

        $this->actingAs($this->cashier)->get("/cash/staff-debt-repayments/{$repayment->uuid}/recu")
            ->assertInertia(fn (Assert $page) => $page->component('Cash/StaffDebtReceipt')->where('receipt.balance', '0.00')->etc());

        // Une autre caissière n'annule pas l'encaissement d'une autre caisse.
        $other = $this->user(['staff_debts.collect'], 'RECEPTION');
        $this->actingAs($other)->post("/cash/staff-debt-repayments/{$repayment->uuid}/cancel", ['reason' => 'Erreur'])->assertSessionHasErrors('repayment');

        $this->actingAs($this->cashier)->post("/cash/staff-debt-repayments/{$repayment->uuid}/cancel", ['reason' => 'Mauvais employé'])->assertSessionHasNoErrors();
        $this->assertSame(StaffDebtStatus::Active, $debt->fresh()->status);
        $this->assertSame('10000.00', $session->fresh()->computeExpectedClosingAmount());
    }

    public function test_my_debts_summary_reads_the_next_repayment_what_is_repaid_and_what_is_late(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->approved($user, 30000, 10000, '2026-09', mode: 'CASH');
        $this->disburse($debt);

        // Rien remis en septembre : la mensualité du mois est en retard.
        $this->actingAs($user)->get('/mes-dettes')->assertInertia(fn (Assert $page) => $page
            ->where('space.summary.balance', '30000.00')
            ->where('space.summary.repaid', '0.00')
            ->where('space.summary.arrears', '10000.00')
            ->where('space.summary.next', ['period' => '2026-09', 'amount' => '10000.00'])
            ->where('space.debts.0.timeline.0.key', 'requested')
            ->etc());

        $this->openSession($this->cashier);
        $this->actingAs($this->cashier)->post("/cash/staff-debts/{$debt->uuid}/repayments", ['amount' => '10000'])->assertSessionHasNoErrors();

        $this->actingAs($user)->get('/mes-dettes')->assertInertia(fn (Assert $page) => $page
            ->where('space.summary.balance', '20000.00')
            ->where('space.summary.repaid', '10000.00')
            ->where('space.summary.arrears', '0.00')
            ->where('space.summary.next', ['period' => '2026-10', 'amount' => '10000.00'])
            ->etc());
    }

    public function test_the_cash_desk_needs_an_open_session_and_a_disbursed_debt(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->approved($user, 30000, 10000, '2026-09', mode: 'CASH');

        $this->actingAs($this->cashier)->post("/cash/staff-debts/{$debt->uuid}/repayments", ['amount' => '10000'])->assertSessionHasErrors('cash_session');
        $this->openSession($this->cashier);
        $this->actingAs($this->cashier)->post("/cash/staff-debts/{$debt->uuid}/repayments", ['amount' => '10000'])->assertSessionHasErrors('debt');

        $this->actingAs($this->hr)->post("/cash/staff-debts/{$debt->uuid}/repayments", ['amount' => '10000'])->assertForbidden();
    }

    public function test_the_dg_writes_off_the_rest_and_cancels_an_undisbursed_approval(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->approved($user, 30000, 10000, '2026-09', mode: 'CASH');

        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/remettre", ['reason' => 'Geste'])->assertSessionHasErrors('debt');
        $this->disburse($debt);
        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/annuler", ['reason' => 'Erreur'])->assertSessionHasErrors('debt');
        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/remettre", ['reason' => 'Décès d’un proche'])->assertSessionHasNoErrors();

        $this->assertSame(StaffDebtStatus::WrittenOff, $debt->fresh()->status);
        $this->assertSame('30000.00', (string) $debt->fresh()->written_off_amount);
        $this->assertSame(0, $debt->fresh()->balanceMinor());

        $other = $this->approved($this->staff(salary: null, number: 'EMP-2')[0], 20000, 10000, '2026-10', mode: 'CASH');
        $this->actingAs($this->dg)->post("/administration/dettes/{$other->uuid}/annuler", ['reason' => 'Budget'])->assertSessionHasNoErrors();
        $this->assertSame(StaffDebtStatus::Cancelled, $other->fresh()->status);
    }

    public function test_the_listing_counts_each_view_and_the_screens_are_served_to_the_portal(): void
    {
        [$user] = $this->staff(salary: 400000);
        $this->request($user, 50000, 10000, '2026-10');
        $this->approved($this->staff(salary: 400000, number: 'EMP-2')[0], 50000, 10000, '2026-10');

        $this->actingAs($this->hr)->get('/administration/dettes')->assertInertia(fn (Assert $page) => $page
            ->component('Administration/StaffDebts/Index')
            ->where('listing.view', 'a-decider')
            ->where('listing.counts', ['a-decider' => 1, 'a-verser' => 1, 'en-cours' => 0, 'closes' => 0])
            ->has('listing.debts', 1)
            ->etc());

        $this->assertTrue(app(SiteHrGateway::class)->isHrScreen('Administration/StaffDebts/Show'));
        $this->actingAs($this->cashier)->get('/administration/dettes')->assertForbidden();
    }

    /** @return array{0: User, 1: Employee} */
    private function staff(?int $salary, string $number = 'EMP-1'): array
    {
        $user = $this->user(['staff_debts.request'], 'NURSE');
        $employee = Employee::query()->create([
            'employee_number' => $number, 'first_name' => 'Vola', 'last_name' => 'RABE '.$number, 'sex' => 'F', 'active' => true,
            'remuneration_type' => $salary ? 'SALARY' : null, 'remuneration_amount' => $salary, 'user_id' => $user->id,
        ]);

        return [$user, $employee];
    }

    private function request(User $user, int $amount, int $installment, string $first): StaffDebt
    {
        $this->actingAs($user)->post('/mes-dettes', [
            'amount' => (string) $amount, 'installment_amount' => (string) $installment, 'first_period' => $first, 'reason' => 'Besoin personnel',
        ])->assertSessionHasNoErrors();

        return StaffDebt::query()->latest('id')->firstOrFail();
    }

    private function approved(User $user, int $amount, int $installment, string $first, string $mode = 'SALARY'): StaffDebt
    {
        $debt = $this->request($user, $amount, $installment, $first);
        $this->actingAs($this->dg)->post("/administration/dettes/{$debt->uuid}/accorder", $this->terms($amount, $installment, $first, $mode))->assertSessionHasNoErrors();

        return $debt->fresh();
    }

    private function disburse(StaffDebt $debt): void
    {
        $this->actingAs($this->hr)->post("/administration/dettes/{$debt->uuid}/verser", ['disbursed_on' => now()->toDateString(), 'disbursement_mode' => 'CASH'])->assertSessionHasNoErrors();
    }

    /** @return array<string, string> */
    private function terms(int $amount, int $installment, string $first, string $mode = 'SALARY'): array
    {
        return ['amount' => (string) $amount, 'installment_amount' => (string) $installment, 'first_period' => $first, 'repayment_mode' => $mode];
    }

    private function openSession(User $user): CashSession
    {
        return CashSession::query()->create([
            'session_number' => 'CS-'.$user->id, 'active_key' => 'SINGLE_OPEN_CASH', 'status' => 'OPEN',
            'opening_amount' => '10000.00', 'opened_by' => $user->id, 'opened_at' => now(),
        ]);
    }

    private function user(array $permissions, string $code = 'ADMINISTRATION'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);

        foreach ($permissions as $name) {
            $role->permissions()->syncWithoutDetaching([Permission::query()->firstOrCreate(['name' => $name])->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
