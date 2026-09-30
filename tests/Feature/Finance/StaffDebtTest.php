<?php

namespace Tests\Feature\Finance;

use App\Enums\StaffDebtStatus;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalaryPayment;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\StaffDebtSetting;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\StaffDebtUpdated;
use App\Services\StaffDebts\StaffDebtReminder;
use App\Services\SuperAdmin\SiteHrGateway;
use App\Services\SuperAdmin\SiteStaffDebtGateway;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-228 / ADR-229 — les dettes du personnel : demandées par l'employé depuis son
 * compte, décidées, versées (hors RIVO) et réglées dans Finance au portail — par l'API
 * du site, au nom du Super Admin —, remboursées par retenue sur la paie du mois (jamais
 * plus que le brut) ou en espèces à la Caisse. Limites, intérêts par tranche, dérogations
 * du DG, relances des retards et export.
 */
class StaffDebtTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_UUID = '6b2d8f1e-3c40-4a57-9d12-8e7f6a5b4c3d';

    private const DG = [
        'staff_debts.view', 'staff_debts.decide', 'staff_debts.write_off', 'staff_debts.disburse',
        'staff_debts.settings', 'staff_debts.export', 'employees.payroll.view',
    ];

    private const HR = ['staff_debts.view', 'salary_payments.view', 'salary_payments.pay', 'salary_payments.cancel'];

    private User $hr;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
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

    public function test_the_dg_approves_with_adjusted_terms_from_the_portal_and_the_employee_is_told(): void
    {
        [$user] = $this->staff(salary: 400000);
        $debt = $this->request($user, 300000, 100000, '2026-10');

        // Sans le droit de décider, rien n'est accordé.
        $this->portal('POST', "{$debt->uuid}/accorder", $this->terms(200000, 50000, '2026-11'), ['staff_debts.view'])->assertForbidden();

        $this->portal('POST', "{$debt->uuid}/accorder", $this->terms(200000, 50000, '2026-11'))->assertOk()->assertJsonPath('redirect', fn ($redirect) => is_string($redirect));

        $debt->refresh();
        $this->assertSame(StaffDebtStatus::Approved, $debt->status);
        $this->assertSame('200000.00', (string) $debt->amount);
        $this->assertSame('2026-11', $debt->first_period->format('Y-m'));
        $this->assertNull($debt->pending_key);
        $this->assertNull($debt->decided_by, 'le Super Admin n’a pas de compte local');
        $this->assertSame(self::ACTOR_UUID, $debt->external_decided_by_uuid);

        // L'employé est prévenu ; le RH du site ne verse plus, il n'est pas prévenu.
        $this->assertSame(1, UserNotification::query()->for($user)->where('type', StaffDebtUpdated::class)->count());
        $this->assertSame(0, UserNotification::query()->for($this->hr)->where('type', StaffDebtUpdated::class)->count());

        $this->portal('GET', $debt->uuid)->assertOk()
            ->assertJsonPath('component', 'Finance/StaffDebts/Show')
            ->assertJsonPath('props.debt.granted.adjusted', true)
            ->assertJsonPath('props.debt.granted.plan.count', 4)
            ->assertJsonPath('props.debt.employee.salary', '400000.00')
            ->assertJsonPath('props.debt.can.adjust', true)
            ->assertJsonPath('props.debt.can.disburse', true);

        // Sans le droit de voir les salaires, le salaire n'est pas servi.
        $this->portal('GET', $debt->uuid, [], ['staff_debts.view'])->assertOk()->assertJsonPath('props.debt.employee.salary', null);
    }

    public function test_salary_repayment_needs_a_declared_salary_and_a_refusal_needs_a_reason(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->request($user, 100000, 50000, '2026-10');

        $this->portal('POST', "{$debt->uuid}/accorder", $this->terms(100000, 50000, '2026-10'))->assertStatus(422)->assertJsonValidationErrors('repayment_mode');
        $this->portal('POST', "{$debt->uuid}/refuser", ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->portal('POST', "{$debt->uuid}/refuser", ['reason' => 'Trop de dettes en cours'])->assertOk();

        $this->assertSame(StaffDebtStatus::Refused, $debt->fresh()->status);
        $this->assertSame('Trop de dettes en cours', $debt->fresh()->refusal_reason);
    }

    public function test_the_portal_marks_it_disbursed_and_the_payroll_retains_the_installment_until_settled(): void
    {
        [$user, $employee] = $this->staff(salary: 400000);
        $debt = $this->approved($user, 250000, 100000, '2026-09');

        $this->portal('POST', "{$debt->uuid}/verser", ['disbursed_on' => '2026-09-21', 'disbursement_mode' => 'CASH'])->assertStatus(422)->assertJsonValidationErrors('disbursed_on');
        $this->portal('POST', "{$debt->uuid}/verser", ['disbursed_on' => '2026-09-20', 'disbursement_mode' => 'BANK', 'reference' => 'VIR-12'])->assertOk();
        $this->assertSame(StaffDebtStatus::Active, $debt->fresh()->status);
        $this->assertSame(self::ACTOR_UUID, $debt->fresh()->external_disbursed_by_uuid);

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

        $this->portal('POST', "{$debt->uuid}/remettre", ['reason' => 'Geste'])->assertStatus(422)->assertJsonValidationErrors('debt');
        $this->disburse($debt);
        $this->portal('POST', "{$debt->uuid}/annuler", ['reason' => 'Erreur'])->assertStatus(422)->assertJsonValidationErrors('debt');
        $this->portal('POST', "{$debt->uuid}/remettre", ['reason' => 'Décès d’un proche'])->assertOk();

        $this->assertSame(StaffDebtStatus::WrittenOff, $debt->fresh()->status);
        $this->assertSame('30000.00', (string) $debt->fresh()->written_off_amount);
        $this->assertSame(0, $debt->fresh()->balanceMinor());

        $other = $this->approved($this->staff(salary: null, number: 'EMP-2')[0], 20000, 10000, '2026-10', mode: 'CASH');
        $this->portal('POST', "{$other->uuid}/annuler", ['reason' => 'Budget'])->assertOk();
        $this->assertSame(StaffDebtStatus::Cancelled, $other->fresh()->status);
    }

    public function test_the_listing_lives_in_finance_on_the_portal_and_left_the_site_hr_area(): void
    {
        [$user] = $this->staff(salary: 400000);
        $this->request($user, 50000, 10000, '2026-10');
        $this->approved($this->staff(salary: 400000, number: 'EMP-2')[0], 50000, 10000, '2026-10');

        $this->portal('GET', '')->assertOk()
            ->assertJsonPath('component', 'Finance/StaffDebts/Index')
            ->assertJsonPath('props.listing.view', 'a-decider')
            ->assertJsonPath('props.listing.counts', ['a-decider' => 1, 'a-verser' => 1, 'en-cours' => 0, 'closes' => 0])
            ->assertJsonCount(1, 'props.listing.debts')
            ->assertJsonPath('props.can.settings', true);

        $this->assertTrue(app(SiteStaffDebtGateway::class)->isScreen('Finance/StaffDebts/Show'));
        $this->assertFalse(app(SiteHrGateway::class)->isHrScreen('Administration/StaffDebts/Show'));

        // Le site n'a plus de rubrique : l'ancienne adresse ramène à l'accueil, en le disant.
        $this->actingAs($this->hr)->get('/administration/dettes')->assertRedirect(route('dashboard'))->assertSessionHas('status');
        $this->actingAs($this->hr)->post("/administration/dettes/{$user->id}/verser")->assertStatus(405);

        // L'API du site exige son jeton.
        $this->withHeaders([...$this->headers(self::DG), 'Authorization' => 'Bearer wrong'])->getJson('/api/v1/super-admin/site-staff-debts')->assertUnauthorized();
    }

    public function test_the_super_admin_sets_the_site_rules_from_the_portal_and_they_are_audited(): void
    {
        $this->portal('GET', 'reglages', [], ['staff_debts.view'])->assertForbidden();
        $this->portal('GET', 'reglages')->assertOk()->assertJsonPath('component', 'Finance/StaffDebts/Settings')->assertJsonPath('props.settings.configured', false);

        // Des tranches qui se chevauchent sont refusées, nommées.
        $this->portal('PUT', 'reglages', $this->settings(['interest_tiers' => [
            ['from' => '0', 'to' => '1000000', 'mode' => 'PERCENT', 'value' => '5'],
            ['from' => '500000', 'to' => null, 'mode' => 'FIXED', 'value' => '30000'],
        ]]))->assertStatus(422)->assertJsonValidationErrors('interest_tiers');
        $this->portal('PUT', 'reglages', $this->settings(['min_amount' => '500000', 'max_amount' => '100000']))->assertStatus(422)->assertJsonValidationErrors('max_amount');

        $this->portal('PUT', 'reglages', $this->settings())->assertOk();

        $setting = StaffDebtSetting::query()->sole();
        $this->assertSame('50000.00', (string) $setting->min_amount);
        $this->assertSame(12, $setting->max_months);
        $this->assertSame([
            ['from' => '0.00', 'to' => '999999.99', 'mode' => 'PERCENT', 'value' => '5.00'],
            ['from' => '1000000.00', 'to' => null, 'mode' => 'FIXED', 'value' => '300000.00'],
        ], $setting->interest_tiers);
        $this->assertSame(self::ACTOR_UUID, $setting->external_updated_by_uuid);

        $audit = AuditLog::query()->where('entity_type', $setting->getMorphClass())->where('entity_id', $setting->id)->firstOrFail();
        $this->assertNull($audit->user_id);
        $this->assertSame(self::ACTOR_UUID, $audit->external_actor_uuid);

        // L'employé lit les règles avant de demander, dont ce que son salaire permet.
        [$user] = $this->staff(salary: 400000);
        $this->actingAs($user)->get('/mes-dettes')->assertInertia(fn (Assert $page) => $page
            ->where('space.rules.configured', true)
            ->where('space.rules.max_months', 12)
            ->where('space.rules.max_installment', '160000.00')
            ->has('space.rules.interest_tiers', 2)
            ->etc());
    }

    public function test_the_tier_interest_is_added_once_and_frozen_at_the_approval(): void
    {
        $this->configure($this->settings(['max_months' => null, 'max_salary_share' => null]));
        [$user] = $this->staff(salary: 2000000);

        // 1 000 000 Ar : tranche fixe de 300 000 Ar.
        $debt = $this->request($user, 1000000, 325000, '2026-10');
        $this->assertSame('300000.00', (string) $debt->requested_interest_amount);
        $this->assertSame(130000000, $debt->requestedTotalMinor());

        $this->portal('POST', "{$debt->uuid}/accorder", $this->terms(1000000, 325000, '2026-10'))->assertOk();
        $debt->refresh();
        $this->assertSame('300000.00', (string) $debt->interest_amount);
        $this->assertSame('FIXED', $debt->interest_mode);
        $this->assertSame(130000000, $debt->totalDueMinor());

        $this->portal('GET', $debt->uuid)->assertOk()
            ->assertJsonPath('props.debt.granted.total', '1300000.00')
            ->assertJsonPath('props.debt.granted.plan.count', 4)
            ->assertJsonPath('props.debt.granted.plan.last_amount', '325000.00');

        // Changer les tranches ensuite ne réécrit pas une dette accordée.
        $this->configure($this->settings(['interest_tiers' => []]));
        $this->disburse($debt);
        $this->assertSame(130000000, $debt->fresh()->balanceMinor());

        // 200 000 Ar à 5 % : 10 000 Ar ; le DG peut remettre l'intérêt.
        $this->configure($this->settings(['max_months' => null, 'max_salary_share' => null]));
        [$other] = $this->staff(salary: 2000000, number: 'EMP-2');
        $small = $this->request($other, 200000, 50000, '2026-10');
        $this->assertSame('10000.00', (string) $small->requested_interest_amount);
        $this->portal('POST', "{$small->uuid}/accorder", [...$this->terms(200000, 50000, '2026-10'), 'waive_interest' => true])->assertOk();
        $this->assertSame('0.00', (string) $small->fresh()->interest_amount);
        $this->assertTrue($small->fresh()->interest_waived);
    }

    public function test_an_employee_cannot_request_outside_the_site_limits(): void
    {
        $this->configure($this->settings([
            'max_open_debts' => 1, 'min_seniority_months' => 6, 'interest_tiers' => [],
        ]));
        [$user, $employee] = $this->staff(salary: 400000);

        $post = fn (array $data) => $this->actingAs($user)->post('/mes-dettes', ['first_period' => '2026-10', 'reason' => 'Besoin personnel', ...$data]);

        // Ancienneté : la date d'entrée manque, puis elle est trop récente.
        $post(['amount' => '100000', 'installment_amount' => '50000'])->assertSessionHasErrors('employee');
        $employee->forceFill(['hire_date' => '2026-06-01'])->save();
        $post(['amount' => '100000', 'installment_amount' => '50000'])->assertSessionHasErrors('employee');
        $employee->forceFill(['hire_date' => '2025-01-01'])->save();

        $post(['amount' => '20000', 'installment_amount' => '10000'])->assertSessionHasErrors('amount');
        $post(['amount' => '20000000', 'installment_amount' => '1000000'])->assertSessionHasErrors('amount');
        // Durée : 1 000 000 en 12 mois au plus ; part du salaire : 40 % de 400 000.
        $post(['amount' => '1000000', 'installment_amount' => '50000'])->assertSessionHasErrors([
            // 1 000 000 / 12 = 83 333,33 : arrondi à l'ariary supérieur, sinon on ne rembourse pas en 12 mois.
            'installment_amount' => 'Remboursement en 12 mois au plus : il faut une mensualité d’au moins 83 334 Ar pour 1 000 000 Ar à rembourser.',
        ]);
        $post(['amount' => '1000000', 'installment_amount' => '200000'])->assertSessionHasErrors('installment_amount');
        $this->assertSame(0, StaffDebt::query()->count());

        $post(['amount' => '1000000', 'installment_amount' => '160000'])->assertSessionHasNoErrors();

        // Une dette engagée au plus : la suivante attend.
        $this->portal('POST', StaffDebt::query()->sole()->uuid.'/accorder', $this->terms(1000000, 160000, '2026-10'))->assertOk();
        $post(['amount' => '100000', 'installment_amount' => '50000'])->assertSessionHasErrors('employee');

        // Demandes fermées : le message du site.
        $this->configure($this->settings(['requests_open' => false, 'closed_message' => 'Reprise en janvier.']));
        [$other] = $this->staff(salary: 400000, number: 'EMP-2');
        $this->actingAs($other)->get('/mes-dettes')->assertInertia(fn (Assert $page) => $page
            ->where('space.can_request', false)->where('space.request_blocker', 'Reprise en janvier.')->etc());
    }

    public function test_the_dg_can_grant_beyond_the_limits_only_by_confirming_a_written_derogation(): void
    {
        [$user] = $this->staff(salary: 400000);
        $debt = $this->request($user, 1000000, 160000, '2026-10');
        $this->configure($this->settings(['max_amount' => '500000', 'interest_tiers' => []]));

        $this->portal('POST', "{$debt->uuid}/accorder", $this->terms(1000000, 160000, '2026-10'))
            ->assertStatus(422)->assertJsonValidationErrors(['amount', 'derogation']);
        $this->assertSame(StaffDebtStatus::Requested, $debt->fresh()->status);

        $this->portal('POST', "{$debt->uuid}/accorder", [...$this->terms(1000000, 160000, '2026-10'), 'accept_derogations' => true])->assertOk();
        $debt->refresh();
        $this->assertSame(StaffDebtStatus::Approved, $debt->status);
        $this->assertSame(['Le montant maximum est de 500 000 Ar.'], $debt->derogations);

        $this->portal('GET', '')->assertOk()->assertJsonPath('props.listing.debts.0.derogated', true);
    }

    public function test_cash_arrears_are_reminded_once_a_month_to_the_employee_and_the_site_hr(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->approved($user, 30000, 10000, '2026-09', mode: 'CASH');
        $this->disburse($debt);

        $reminder = app(StaffDebtReminder::class);
        $this->assertSame(1, $reminder->remindAll());
        $this->assertSame(0, $reminder->remindAll(), 'une seule relance par mois');
        $this->assertSame(1, UserNotification::query()->for($user)->where('type', StaffDebtUpdated::class)->where('data->kind', 'staff_debt.late')->count());
        $this->assertSame(1, UserNotification::query()->for($this->hr)->where('type', StaffDebtUpdated::class)->count());
        $this->assertSame(0, UserNotification::query()->for($this->cashier)->where('type', StaffDebtUpdated::class)->count());

        // Le DG relance à la main, même déjà relancée ce mois-ci ; c'est audité.
        $this->portal('POST', "{$debt->uuid}/relancer")->assertOk();
        $this->assertSame(2, UserNotification::query()->for($this->hr)->where('type', StaffDebtUpdated::class)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'staff_debt.remind')->where('external_actor_uuid', self::ACTOR_UUID)->exists());

        $this->artisan('rivo:staff-debts:remind')->assertSuccessful();

        // Rien en retard : rien à relancer.
        $this->openSession($this->cashier);
        $this->actingAs($this->cashier)->post("/cash/staff-debts/{$debt->uuid}/repayments", ['amount' => '10000'])->assertSessionHasNoErrors();
        $this->portal('POST', "{$debt->uuid}/relancer")->assertStatus(422)->assertJsonValidationErrors('debt');
    }

    public function test_the_list_exports_to_excel_and_the_export_is_audited(): void
    {
        [$user] = $this->staff(salary: 400000);
        $debt = $this->approved($user, 50000, 10000, '2026-10');

        $this->portal('GET', 'export', ['vue' => 'a-verser'], ['staff_debts.view'])->assertForbidden();

        $response = $this->portal('GET', 'export', ['vue' => 'a-verser']);
        $response->assertOk();
        $this->assertStringContainsString('dettes-du-personnel-a-', (string) $response->headers->get('content-disposition'));

        $audit = AuditLog::query()->where('action', 'staff_debt.export')->sole();
        $this->assertSame(self::ACTOR_UUID, $audit->external_actor_uuid);
        $this->assertSame([$debt->number], $audit->new_values['debts']);
    }

    public function test_the_site_overview_gives_the_portal_counts_and_money_without_names(): void
    {
        [$user] = $this->staff(salary: null);
        $debt = $this->approved($user, 30000, 10000, '2026-09', mode: 'CASH');
        $this->disburse($debt);
        $this->request($this->staff(salary: null, number: 'EMP-2')[0], 20000, 10000, '2026-10');

        $this->withHeaders($this->headers(['staff_debts.view']))->getJson('/api/v1/super-admin/staff-debts/overview')
            ->assertOk()
            ->assertJsonPath('data.counts', ['a-decider' => 1, 'a-verser' => 0, 'en-cours' => 1, 'closes' => 0])
            ->assertJsonPath('data.balance', '30000.00')
            ->assertJsonPath('data.arrears', '10000.00')
            ->assertJsonPath('data.late', 1)
            ->assertJsonPath('data.requested_amount', '20000.00')
            ->assertJsonMissingPath('data.debts');

        $this->withHeaders($this->headers([]))->getJson('/api/v1/super-admin/staff-debts/overview')->assertForbidden();
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
        $this->portal('POST', "{$debt->uuid}/accorder", $this->terms($amount, $installment, $first, $mode))->assertOk();

        return $debt->fresh();
    }

    private function disburse(StaffDebt $debt): void
    {
        $this->portal('POST', "{$debt->uuid}/verser", ['disbursed_on' => now()->toDateString(), 'disbursement_mode' => 'CASH'])->assertOk();
    }

    /**
     * Le Super Admin du portail, par l'API du site (ADR-229).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $permissions
     */
    private function portal(string $method, string $path, array $data = [], ?array $permissions = null): TestResponse
    {
        // Le portail n'a pas de session sur le site : rien de ce qu'un écran du site a mis en session ne le suit.
        $this->flushSession();
        $url = '/api/v1/super-admin/site-staff-debts'.($path !== '' ? '/'.$path : '');
        $headers = $this->headers($permissions ?? self::DG);

        if ($method === 'GET') {
            return $this->withHeaders($headers)->getJson($url.($data !== [] ? '?'.http_build_query($data) : ''));
        }

        return $this->withHeaders([...$headers, 'Idempotency-Key' => (string) Str::uuid()])->json($method, $url, $data);
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => self::ACTOR_UUID,
            'X-Rivo-Actor-Name' => 'Direction générale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function settings(array $overrides = []): array
    {
        return [
            'requests_open' => true, 'closed_message' => null,
            'min_amount' => '50000', 'max_amount' => '10000000', 'max_months' => 12, 'max_salary_share' => 40,
            'max_open_debts' => null, 'min_seniority_months' => null, 'exclude_interns' => false,
            'interest_tiers' => [
                ['from' => '0', 'to' => '999999.99', 'mode' => 'PERCENT', 'value' => '5'],
                ['from' => '1000000', 'to' => null, 'mode' => 'FIXED', 'value' => '300000'],
            ],
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $settings */
    private function configure(array $settings): void
    {
        $this->portal('PUT', 'reglages', $settings)->assertOk();
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
