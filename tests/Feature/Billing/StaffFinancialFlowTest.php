<?php

namespace Tests\Feature\Billing;

use App\Actions\Administration\AllocateStaffBlockCreditAction;
use App\Actions\Administration\LinkPatientToEmployeeAction;
use App\Actions\Billing\CancelBillableItemAction;
use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\RecordBillableItemAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\PlanEpisodeRoutingAction;
use App\Actions\Episode\SetEpisodeFinancialContextAction;
use App\Actions\Reception\CompleteEpisodeServicesAction;
use App\Enums\ArrivalPaymentChoice;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodePriority;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffBlockCreditMovementType;
use App\Enums\StaffCoveragePolicy;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StaffBlockCreditMovement;
use App\Models\User;
use App\Services\Finance\StaffBlockCreditLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class StaffFinancialFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $reception;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.code' => 'M']);

        $receptionRole = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);
        $financeRole = Role::query()->create(['code' => 'ADMINISTRATION', 'name' => 'Administration / RH']);

        foreach ([
            'episodes.create', 'episodes.update', 'employees.patient_lookup',
            'patient_staff_links.create', 'billing.create',
        ] as $name) {
            $permission = Permission::query()->create(['name' => $name]);
            $receptionRole->permissions()->attach($permission);
        }

        foreach (['staff_block_credits.view', 'staff_block_credits.allocate'] as $name) {
            $permission = Permission::query()->create(['name' => $name]);
            $financeRole->permissions()->attach($permission);
        }

        $this->reception = User::factory()->create(['role_id' => $receptionRole->id]);
        $this->finance = User::factory()->create(['role_id' => $financeRole->id]);
        $this->actingAs($this->reception);
    }

    public function test_staff_ordinary_service_is_fully_covered_without_consuming_block_credit(): void
    {
        [$patient, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $service = $this->service(
            StaffCoveragePolicy::OrdinaryFullCoverage,
            '20000.00',
            CatalogModule::Medicine,
            'Consultation générale',
        );

        $item = $this->record($episode, $service);
        $invoice = app(CreateInvoiceAction::class)->execute($patient, [
            'episode_uuid' => $episode->uuid,
            'billable_item_uuids' => [$item->uuid],
        ], $this->reception);

        $this->assertSame('20000.00', $item->gross_amount);
        $this->assertSame('20000.00', $item->staff_covered_amount);
        $this->assertSame('0.00', $item->staff_block_credit_used);
        $this->assertSame('0.00', $item->patient_amount);
        $this->assertSame('20000.00', $invoice->subtotal_amount);
        $this->assertSame('20000.00', $invoice->staff_covered_amount);
        $this->assertSame('0.00', $invoice->total_amount);
        $this->assertSame('20000.00', $invoice->lines()->sole()->gross_line_total);
        $this->assertSame('0.00', $invoice->lines()->sole()->line_total);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
        $this->assertDatabaseMissing('staff_block_credit_movements', [
            'movement_type' => StaffBlockCreditMovementType::Consumption->value,
        ]);
    }

    public function test_staff_block_service_uses_sufficient_credit_and_keeps_the_gross_amount(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '300000.00');

        $item = $this->record($episode, $service);

        $this->assertSame('300000.00', $item->gross_amount);
        $this->assertSame('300000.00', $item->staff_covered_amount);
        $this->assertSame('300000.00', $item->staff_block_credit_used);
        $this->assertSame('0.00', $item->patient_amount);
        $this->assertSame('200000.00', $this->summary($employee)['available']);
    }

    public function test_staff_block_service_uses_remaining_credit_and_leaves_the_remainder_to_the_patient(): void
    {
        [$patient, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '200000.00');
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '350000.00');

        $item = $this->record($episode, $service);
        $invoice = app(CreateInvoiceAction::class)->execute($patient, [
            'episode_uuid' => $episode->uuid,
            'billable_item_uuids' => [$item->uuid],
        ], $this->reception);

        $line = $invoice->lines()->sole();
        $this->assertSame('350000.00', $item->gross_amount);
        $this->assertSame('200000.00', $item->staff_block_credit_used);
        $this->assertSame('150000.00', $item->patient_amount);
        $this->assertSame('350000.00', $invoice->subtotal_amount);
        $this->assertSame('200000.00', $invoice->staff_block_credit_used);
        $this->assertSame('150000.00', $invoice->total_amount);
        $this->assertSame('350000.00', $line->gross_line_total);
        $this->assertSame('200000.00', $line->staff_block_credit_used);
        $this->assertSame('150000.00', $line->line_total);
        $this->assertSame('0.00', $this->summary($employee)['available']);
    }

    public function test_staff_block_service_is_fully_payable_when_credit_is_exhausted(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '250000.00');

        $item = $this->record($episode, $service);

        $this->assertSame('250000.00', $item->gross_amount);
        $this->assertSame('0.00', $item->staff_covered_amount);
        $this->assertSame('0.00', $item->staff_block_credit_used);
        $this->assertSame('250000.00', $item->patient_amount);
        $this->assertSame('0.00', $this->summary($employee)['available']);
        $this->assertDatabaseCount('staff_block_credit_movements', 0);
    }

    public function test_staff_not_covered_service_is_fully_payable_and_preserves_credit(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '400000.00');
        $service = $this->service(StaffCoveragePolicy::NotCovered, '45000.00');

        $item = $this->record($episode, $service);

        $this->assertSame('45000.00', $item->gross_amount);
        $this->assertSame('0.00', $item->staff_covered_amount);
        $this->assertSame('0.00', $item->staff_block_credit_used);
        $this->assertSame('45000.00', $item->patient_amount);
        $this->assertSame('400000.00', $this->summary($employee)['available']);
    }

    public function test_unclassified_staff_service_keeps_finance_pending_without_blocking_clinical_routing(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '300000.00');
        $service = $this->legacyUnclassifiedService(
            'Intervention chirurgicale à classifier',
            CatalogModule::Surgery,
        );

        $result = $this->complete($episode, $service);

        $request = $episode->serviceRequests()->sole();
        $this->assertSame(StaffCoveragePolicy::Unclassified, $service->fresh()->staff_coverage_policy);
        $this->assertSame(StaffCoveragePolicy::Unclassified, $request->staff_coverage_policy);
        $this->assertNull($request->staff_covered_amount);
        $this->assertNull($request->staff_block_credit_used);
        $this->assertNull($request->patient_amount);
        $this->assertNull($result->invoice);
        $this->assertStringContainsString('classifier', $result->billingWarning);
        $this->assertTrue($episode->fresh()->orientations()->exists());
        $this->assertDatabaseCount('billable_items', 0);
        $this->assertSame('300000.00', $this->summary($employee)['available']);
    }

    public function test_ordinary_surgery_consultation_never_consumes_block_credit(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $service = $this->service(
            StaffCoveragePolicy::OrdinaryFullCoverage,
            '30000.00',
            CatalogModule::Surgery,
            'Consultation chirurgien',
        );

        $item = $this->record($episode, $service);

        $this->assertSame('0.00', $item->patient_amount);
        $this->assertSame('0.00', $item->staff_block_credit_used);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
    }

    public function test_a_name_containing_block_does_not_override_the_explicit_ordinary_policy(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $service = $this->service(
            StaffCoveragePolicy::OrdinaryFullCoverage,
            '18000.00',
            CatalogModule::Medicine,
            'Consultation de suivi Bloc',
        );

        $item = $this->record($episode, $service);

        $this->assertSame('18000.00', $item->staff_covered_amount);
        $this->assertSame('0.00', $item->staff_block_credit_used);
        $this->assertSame('0.00', $item->patient_amount);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
    }

    public function test_successive_block_services_share_the_employee_balance_without_going_negative(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $firstService = $this->service(StaffCoveragePolicy::BlockCredit, '300000.00');
        $secondService = $this->service(StaffCoveragePolicy::BlockCredit, '350000.00');

        $first = $this->record($episode, $firstService);
        $second = $this->record($episode, $secondService);

        $this->assertSame('300000.00', $first->staff_block_credit_used);
        $this->assertSame('200000.00', $second->staff_block_credit_used);
        $this->assertSame('150000.00', $second->patient_amount);
        $this->assertSame('0.00', $this->summary($employee)['available']);
        $this->assertDatabaseMissing('staff_block_credit_movements', ['balance_after' => '-0.01']);
    }

    public function test_double_submit_with_the_same_key_returns_one_billable_and_one_consumption(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '120000.00');
        $key = (string) Str::uuid();

        $first = $this->record($episode, $service, $key);
        $second = $this->record($episode, $service, $key);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('billable_items', 1);
        $this->assertSame(1, StaffBlockCreditMovement::query()
            ->where('movement_type', StaffBlockCreditMovementType::Consumption->value)
            ->count());
        $this->assertSame('380000.00', $this->summary($employee)['available']);
    }

    public function test_retry_from_the_same_financial_source_debits_credit_only_once(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '90000.00');
        app(PlanEpisodeRoutingAction::class)->execute($episode, [[
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
        ]], $this->reception);

        $first = app(RecordBillableItemAction::class)->execute($episode, [
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
        ], $this->reception);
        $second = app(RecordBillableItemAction::class)->execute($episode, [
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
        ], $this->reception);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StaffBlockCreditMovement::query()
            ->where('movement_type', StaffBlockCreditMovementType::Consumption->value)
            ->count());
        $this->assertSame('410000.00', $this->summary($employee)['available']);
    }

    public function test_serialized_competing_consumptions_can_never_spend_more_than_available_credit(): void
    {
        [$patient, $employee, $firstEpisode] = $this->staffContext();
        $this->allocate($employee, '100000.00');
        $secondEpisode = $this->episode($patient, EpisodeFinancialMode::Staff, $employee);
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '80000.00');

        $first = $this->record($firstEpisode, $service);
        $second = $this->record($secondEpisode, $service);

        $this->assertSame('80000.00', $first->staff_block_credit_used);
        $this->assertSame('20000.00', $second->staff_block_credit_used);
        $this->assertSame('60000.00', $second->patient_amount);
        $this->assertSame('100000.00', $this->summary($employee)['consumed']);
        $this->assertSame('0.00', $this->summary($employee)['available']);
        $this->assertTrue(StaffBlockCreditMovement::query()->get()
            ->every(fn (StaffBlockCreditMovement $movement) => (float) $movement->balance_after >= 0));
    }

    public function test_cancellation_appends_a_reversal_without_deleting_the_consumption(): void
    {
        [, $employee, $episode] = $this->staffContext();
        $this->allocate($employee, '500000.00');
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '200000.00');
        $item = $this->record($episode, $service);
        $consumption = StaffBlockCreditMovement::query()
            ->where('movement_type', StaffBlockCreditMovementType::Consumption->value)
            ->sole();

        app(CancelBillableItemAction::class)->execute(
            $item,
            'Intervention non réalisée',
            $this->reception,
        );

        $reversal = StaffBlockCreditMovement::query()
            ->where('movement_type', StaffBlockCreditMovementType::Reversal->value)
            ->sole();
        $this->assertDatabaseHas('staff_block_credit_movements', [
            'id' => $consumption->id,
            'amount' => '-200000.00',
        ]);
        $this->assertSame($consumption->id, $reversal->reversal_of_id);
        $this->assertSame('200000.00', $reversal->amount);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
        $this->assertDatabaseCount('staff_block_credit_movements', 3);
    }

    public function test_self_episode_never_uses_staff_credit(): void
    {
        $patient = $this->patient();
        $employee = $this->employee();
        $this->allocate($employee, '500000.00');
        $episode = $this->episode($patient, EpisodeFinancialMode::Self);
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '80000.00');

        $item = $this->record($episode, $service);

        $this->assertSame('0.00', $item->coverage_amount);
        $this->assertSame('80000.00', $item->patient_amount);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
        $this->assertDatabaseMissing('staff_block_credit_movements', [
            'movement_type' => StaffBlockCreditMovementType::Consumption->value,
        ]);
    }

    public function test_mutual_episode_never_uses_staff_credit(): void
    {
        $patient = $this->patient();
        $employee = $this->employee();
        $this->allocate($employee, '500000.00');
        $organization = MutualOrganization::query()->create([
            'name' => 'Mutuelle test',
            'coverage_rate' => '80.00',
            'active' => true,
        ]);
        $mutualPermission = Permission::query()->create(['name' => 'mutual_organizations.view']);
        $this->reception->role->permissions()->attach($mutualPermission);
        $episode = $this->episode($patient, EpisodeFinancialMode::Mutual, organization: $organization);
        $service = $this->service(
            StaffCoveragePolicy::BlockCredit,
            '100000.00',
            mutualAmount: '100000.00',
        );

        $item = $this->record($episode, $service);

        $this->assertSame('80000.00', $item->coverage_amount);
        $this->assertSame('20000.00', $item->patient_amount);
        $this->assertSame('0.00', $item->staff_block_credit_used);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
    }

    public function test_employee_linked_to_patient_does_not_apply_staff_credit_to_a_self_episode(): void
    {
        $patient = $this->patient();
        $employee = $this->employee();
        app(LinkPatientToEmployeeAction::class)->execute($patient, $employee, $this->reception);
        $this->allocate($employee, '500000.00');
        $episode = $this->episode($patient, EpisodeFinancialMode::Self);
        $service = $this->service(StaffCoveragePolicy::BlockCredit, '60000.00');

        $item = $this->record($episode, $service);

        $this->assertSame(EpisodeFinancialMode::Self, $episode->financial_mode);
        $this->assertSame('60000.00', $item->patient_amount);
        $this->assertSame('0.00', $item->staff_block_credit_used);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
    }

    public function test_staff_financial_pending_never_blocks_emergency_clinical_queues(): void
    {
        [, $employee, $episode] = $this->staffContext(EpisodePriority::Emergency);
        $this->allocate($employee, '500000.00');
        $service = $this->legacyUnclassifiedService('Urgence au Bloc', CatalogModule::Surgery);

        $result = $this->complete($episode, $service);

        $this->assertNull($result->invoice);
        $this->assertNotNull($result->billingWarning);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Care->value,
        ]);
        $this->assertDatabaseHas('episode_orientations', [
            'episode_id' => $episode->id,
            'destination_module' => CatalogModule::Medicine->value,
        ]);
        $this->assertSame('500000.00', $this->summary($employee)['available']);
    }

    public function test_reception_cannot_allocate_staff_block_credit(): void
    {
        $employee = $this->employee();

        $this->get('/administration/staff-block-credits')->assertForbidden();
        $this->post("/administration/staff-block-credits/{$employee->uuid}", [
            'amount' => '100000.00',
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Allocation interdite depuis la Réception',
        ])->assertForbidden();
        $this->assertDatabaseCount('staff_block_credit_movements', 0);

        $this->expectException(AuthorizationException::class);

        app(AllocateStaffBlockCreditAction::class)->execute(
            $employee,
            '100000.00',
            (string) Str::uuid(),
            'Allocation interdite depuis la Réception',
            $this->reception,
        );
    }

    public function test_authorized_administration_can_allocate_configurable_credit_and_replay_safely(): void
    {
        $employee = $this->employee();
        $key = (string) Str::uuid();

        $this->actingAs($this->finance)
            ->get('/administration/staff-block-credits')
            ->assertOk();

        $first = app(AllocateStaffBlockCreditAction::class)->execute(
            $employee,
            '275000.00',
            $key,
            'Allocation RH manuelle de test',
            $this->finance,
        );
        $second = app(AllocateStaffBlockCreditAction::class)->execute(
            $employee,
            '275000.00',
            $key,
            'Allocation RH manuelle de test',
            $this->finance,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame($this->finance->id, $first->created_by);
        $this->assertSame('275000.00', $first->balance_after);
        $this->assertDatabaseCount('staff_block_credit_movements', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create',
            'module' => 'administration',
            'entity_type' => StaffBlockCreditMovement::class,
            'entity_id' => $first->id,
        ]);
    }

    public function test_staff_credit_history_is_immutable(): void
    {
        $employee = $this->employee();
        $movement = $this->allocate($employee, '100000.00');

        try {
            $movement->update(['reason' => 'Altération interdite']);
            $this->fail('Un mouvement financier ne doit pas pouvoir être modifié.');
        } catch (LogicException) {
            $this->assertSame('Allocation RH manuelle', $movement->fresh()->reason);
        }

        try {
            $movement->delete();
            $this->fail('Un mouvement financier ne doit pas pouvoir être supprimé.');
        } catch (LogicException) {
            $this->assertDatabaseHas('staff_block_credit_movements', ['id' => $movement->id]);
        }

        try {
            StaffBlockCreditMovement::query()->whereKey($movement)->delete();
            $this->fail('Une suppression en masse du registre doit aussi être interdite.');
        } catch (LogicException) {
            $this->assertDatabaseCount('staff_block_credit_movements', 1);
        }

        try {
            $movement->forceDelete();
            $this->fail('Une suppression définitive du registre doit aussi être interdite.');
        } catch (LogicException) {
            $this->assertDatabaseCount('staff_block_credit_movements', 1);
        }
    }

    /** @return array{0: Patient, 1: Employee, 2: Episode} */
    private function staffContext(EpisodePriority $priority = EpisodePriority::Normal): array
    {
        $patient = $this->patient();
        $employee = $this->employee();
        app(LinkPatientToEmployeeAction::class)->execute($patient, $employee, $this->reception);
        $episode = $this->episode($patient, EpisodeFinancialMode::Staff, $employee, priority: $priority);

        return [$patient, $employee, $episode];
    }

    private function patient(): Patient
    {
        return Patient::query()->create([
            'patient_number' => 'M-'.fake()->unique()->numerify('26-#####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('#####'),
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'sex' => 'F',
            'birth_date' => '1990-01-01',
            'active' => true,
        ]);
    }

    private function episode(
        Patient $patient,
        EpisodeFinancialMode $mode,
        ?Employee $employee = null,
        ?MutualOrganization $organization = null,
        EpisodePriority $priority = EpisodePriority::Normal,
    ): Episode {
        $episode = app(CreateEpisodeAction::class)->execute($patient, $priority, $this->reception);
        $context = match ($mode) {
            EpisodeFinancialMode::Staff => ['employee_uuid' => $employee?->uuid],
            EpisodeFinancialMode::Mutual => [
                'mutual_organization_uuid' => $organization?->uuid,
                'employer_name' => 'Employeur test',
                'beneficiary_type' => 'PRINCIPAL',
                'membership_number' => 'MUT-TEST-001',
            ],
            EpisodeFinancialMode::Self => [],
        };

        return app(SetEpisodeFinancialContextAction::class)->execute(
            $episode,
            $mode,
            $context,
            $this->reception,
        );
    }

    private function service(
        StaffCoveragePolicy $policy,
        string $amount,
        CatalogModule $module = CatalogModule::Surgery,
        string $name = 'Intervention forfait Bloc',
        ?string $mutualAmount = null,
    ): CatalogItem {
        $item = CatalogItem::query()->create([
            'code' => 'STAFF-'.fake()->unique()->numerify('#####'),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'staff_coverage_policy' => $policy,
            'created_by' => $this->reception->id,
            'updated_by' => $this->reception->id,
        ]);
        $this->tariff($item, CatalogTariffCategory::Standard, $amount);

        if ($mutualAmount !== null) {
            $this->tariff($item, CatalogTariffCategory::Mutual, $mutualAmount);
        }

        return $item;
    }

    private function legacyUnclassifiedService(string $name, CatalogModule $module): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'code' => 'LEGACY-'.fake()->unique()->numerify('#####'),
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => true,
            'reception_routing_mode' => ReceptionRoutingMode::MedicineDirect,
            'created_by' => $this->reception->id,
            'updated_by' => $this->reception->id,
        ]);
        $this->tariff($item, CatalogTariffCategory::Standard, '30000.00');

        return $item->refresh();
    }

    private function tariff(
        CatalogItem $item,
        CatalogTariffCategory $category,
        string $amount,
    ): CatalogTariff {
        return CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'tariff_category' => $category,
            'amount' => $amount,
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif de test STAFF',
            'created_by' => $this->reception->id,
        ]);
    }

    private function record(
        Episode $episode,
        CatalogItem $service,
        ?string $idempotencyKey = null,
    ): BillableItem {
        return app(RecordBillableItemAction::class)->execute($episode, [
            'catalog_item_uuid' => $service->uuid,
            'quantity' => 1,
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        ], $this->reception);
    }

    private function allocate(Employee $employee, string $amount): StaffBlockCreditMovement
    {
        return app(AllocateStaffBlockCreditAction::class)->execute(
            $employee,
            $amount,
            (string) Str::uuid(),
            'Allocation RH manuelle',
            $this->finance,
        );
    }

    /** @return array{allocated: string, consumed: string, reversed: string, available: string} */
    private function summary(Employee $employee): array
    {
        return app(StaffBlockCreditLedger::class)->summary($employee);
    }

    private function complete(Episode $episode, CatalogItem $service)
    {
        return app(CompleteEpisodeServicesAction::class)->execute(
            episode: $episode,
            actor: $this->reception,
            catalogLines: [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => 1,
            ]],
            designationDeferred: false,
            paymentChoice: ArrivalPaymentChoice::Later,
        );
    }
}
