<?php

namespace Tests\Feature\Reception;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeFinancialMode;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\MutualOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\StaffBlockCreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_need_page_only_exposes_configured_reception_services_and_keeps_pharmacy_separate(): void
    {
        $actor = $this->receptionist(['episodes.create']);
        $eligible = $this->service($actor, 'CONS-NEW', 'Consultation nouvelle', CatalogModule::Medicine);
        $this->service(
            $actor,
            'LAB-HIDDEN',
            'Analyse non validée',
            CatalogModule::Laboratory,
            receptionSelectable: false,
        );
        $this->service(
            $actor,
            'NO-ROUTE',
            'Prestation sans parcours',
            CatalogModule::Care,
            routing: null,
        );
        CatalogItem::query()->create([
            'code' => 'MED-NOT-RECEPTION',
            'name' => 'Médicament hors panier Réception',
            'type' => CatalogItemType::Medicine,
            'module' => CatalogModule::Pharmacy,
            'unit' => 'boîte',
            'billable' => true,
            'stockable' => true,
            'reception_selectable' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $this->actingAs($actor)->get('/reception/patients')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->has('estimateCatalog', 1)
                ->where('estimateCatalog.0.catalog_item_uuid', $eligible->uuid)
                ->where('estimateCatalog.0.module_label', 'Médecine')
                ->where('estimateCatalog.0.routing_mode', ReceptionRoutingMode::MedicineDirect->value)
                ->where('capabilities.can_open_pharmacy_counter_sale', false)
                ->where('capabilities.can_manage_catalog', false));
    }

    public function test_temporary_estimate_recalculates_server_prices_and_creates_no_business_record(): void
    {
        $actor = $this->receptionist(['episodes.create']);
        $service = $this->service($actor, 'ECHO-PREVIEW', 'Échographie', CatalogModule::Medicine, '25000.00');

        $this->actingAs($actor)->postJson('/reception/estimates', [
            'lines' => [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => 2,
            ]],
        ])->assertOk()
            ->assertJsonPath('lines.0.unit_price', '25000.00')
            ->assertJsonPath('lines.0.line_total', '50000.00')
            ->assertJsonPath('total_amount', '50000.00');

        $this->actingAs($actor)->postJson('/reception/estimates', [
            'lines' => [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => 2,
                'unit_price' => '1.00',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->assertDatabaseCount('episode_service_requests', 0);
        $this->assertDatabaseCount('episode_orientations', 0);
        $this->assertDatabaseCount('billable_items', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_normal_journey_creates_one_episode_then_sets_mutual_context_previews_and_routes(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'patients.view',
            'episodes.create', 'episodes.update',
            'mutual_organizations.view',
            'billing.create', 'billing.validate',
        ]);
        $service = $this->service(
            $actor,
            'ECHO-MUTUAL-JOURNEY',
            'Échographie mutualiste',
            CatalogModule::Medicine,
            '40000.00',
            mutualAmount: '30000.00',
        );
        $organization = MutualOrganization::query()->create([
            'name' => 'Mutuelle existante',
            'coverage_rate' => '75.00',
            'active' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $arrival = $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
            'reception_draft' => [
                'designation_deferred' => false,
                'catalog_lines' => [[
                    'catalog_item_uuid' => $service->uuid,
                    'quantity' => 2,
                ]],
            ],
        ])->assertCreated()
            ->assertJsonPath('episode.financial_mode', null);

        $episode = Episode::query()->sole();
        $this->assertNotNull($episode->uuid);
        $arrival->assertJsonPath(
            'resume_url',
            route('reception.passages.journey.show', $episode),
        );
        $this->assertSame(1, $episode->patient->episodes()->count());
        $this->assertDatabaseCount('reception_journey_drafts', 1);

        $this->actingAs($actor)->get(route('reception.passages.journey.show', $episode))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('resumeEpisode.uuid', $episode->uuid)
                ->where('resumeEpisode.financial_mode', null)
                ->where('receptionDraft.designation_deferred', false)
                ->where('receptionDraft.catalog_lines.0.catalog_item_uuid', $service->uuid)
                ->where('receptionDraft.catalog_lines.0.quantity', '2.00')
                ->where('financialPreview', null));

        $context = $this->actingAs($actor)->postJson(
            route('reception.passages.financial-context.store', $episode),
            [
                'financial_mode' => EpisodeFinancialMode::Mutual->value,
                'mutual_organization_uuid' => $organization->uuid,
                'employer_name' => 'Entreprise Exemple',
                'beneficiary_type' => 'PRINCIPAL',
                'membership_number' => 'MAT-001',
                'lines' => [[
                    'catalog_item_uuid' => $service->uuid,
                    'quantity' => 2,
                ]],
            ],
        );

        $context->assertOk()
            ->assertJsonPath('episode.financial_mode', 'MUTUAL')
            ->assertJsonPath('preview.tariff_category', 'MUTUAL')
            ->assertJsonPath('preview.totals.gross_amount', '60000.00')
            ->assertJsonPath('preview.totals.coverage_amount', '45000.00')
            ->assertJsonPath('preview.totals.patient_amount', '15000.00')
            ->assertJsonPath('preview.initial_destination.module', 'MEDICINE');

        $this->actingAs($actor)->get(route('reception.passages.journey.show', $episode))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('resumeEpisode.uuid', $episode->uuid)
                ->where('resumeEpisode.financial_mode', 'MUTUAL')
                ->where('resumeEpisode.mutual_coverage.mutual_organization_uuid', $organization->uuid)
                ->where('financialPreview.totals.patient_amount', '15000.00'));

        $this->assertDatabaseCount('mutual_organizations', 1);
        $this->assertDatabaseCount('episode_mutual_coverages', 1);
        $this->assertDatabaseCount('episode_service_requests', 0);
        $this->assertDatabaseCount('episode_orientations', 0);
        $this->assertDatabaseCount('billable_items', 0);
        $this->assertDatabaseCount('invoices', 0);

        $this->actingAs($actor)->post(route('reception.passages.services.store', $episode), [
            'catalog_lines' => [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => 2,
            ]],
            'payment_choice' => 'LATER',
        ])->assertRedirect();

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('episode_service_requests', 1);
        $this->assertDatabaseCount('episode_orientations', 1);
        $this->assertDatabaseCount('billable_items', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('reception_journey_drafts', 0);
        $this->assertSame('15000.00', Invoice::query()->sole()->total_amount);
    }

    public function test_unknown_need_accepts_an_intentionally_empty_catalog_and_resumes_the_same_episode(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'patients.view',
            'episodes.create', 'episodes.update',
        ]);

        $arrival = $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'first_name' => 'Craig',
            'last_name' => 'Willis',
            'age' => 63,
            'sex' => 'M',
            'reception_draft' => [
                'designation_deferred' => true,
                'catalog_lines' => [],
            ],
        ])->assertCreated();

        $episode = Episode::query()->sole();
        $draft = $episode->receptionJourneyDraft()->sole();

        $this->assertTrue($draft->designation_deferred);
        $this->assertSame([], $draft->catalog_lines);
        $arrival->assertJsonPath(
            'resume_url',
            route('reception.passages.journey.show', $episode),
        );

        $this->actingAs($actor)->get(route('reception.passages.journey.show', $episode))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('resumeEpisode.uuid', $episode->uuid)
                ->where('receptionDraft.designation_deferred', true)
                ->where('receptionDraft.catalog_lines', []));

        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('reception_journey_drafts', 1);
    }

    public function test_staff_preview_simulates_block_credit_without_movement_then_commit_consumes_it(): void
    {
        $actor = $this->receptionist([
            'patients.create', 'patients.view',
            'episodes.create', 'episodes.update',
            'employees.patient_lookup', 'patient_staff_links.create',
            'billing.create', 'billing.validate',
        ]);
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-JOURNEY-01',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'sex' => 'F',
            'birth_date' => '1988-03-09',
            'profession' => 'Infirmière',
            'phone' => '0340000000',
            'email' => 'prive@example.mg',
            'active' => true,
        ]);
        $service = $this->service(
            $actor,
            'BLOCK-JOURNEY',
            'Acte Bloc configuré',
            CatalogModule::Surgery,
            '100000.00',
            policy: StaffCoveragePolicy::BlockCredit,
        );
        app(StaffBlockCreditLedger::class)->allocate(
            $employee,
            '60000.00',
            'journey-allocation-01',
            'Allocation test parcours',
            $actor,
        );

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'first_name' => 'Fara',
            'last_name' => 'Rasoa',
            'birth_date' => '1988-03-09',
            'sex' => 'F',
        ])->assertCreated();
        $episode = Episode::query()->sole();

        $this->actingAs($actor)->getJson('/reception/employees/patient-lookup?q=EMP-JOURNEY')
            ->assertOk()
            ->assertJsonPath('data.0.employee_number', 'EMP-JOURNEY-01')
            ->assertJsonPath('data.0.eligible', true)
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.identity_document_number');

        $this->actingAs($actor)->postJson(
            route('reception.passages.financial-context.store', $episode),
            [
                'financial_mode' => EpisodeFinancialMode::Staff->value,
                'employee_uuid' => $employee->uuid,
                'lines' => [[
                    'catalog_item_uuid' => $service->uuid,
                    'quantity' => 1,
                ]],
            ],
        )->assertOk()
            ->assertJsonPath('preview.lines.0.staff_coverage_policy', 'BLOCK_CREDIT')
            ->assertJsonPath('preview.lines.0.staff_block_credit_used', '60000.00')
            ->assertJsonPath('preview.totals.coverage_amount', '60000.00')
            ->assertJsonPath('preview.totals.patient_amount', '40000.00');

        $this->assertDatabaseCount('patient_staff_links', 1);
        $this->assertDatabaseCount('staff_block_credit_movements', 1);
        $this->assertDatabaseCount('billable_items', 0);

        $this->actingAs($actor)->post(route('reception.passages.services.store', $episode), [
            'catalog_lines' => [[
                'catalog_item_uuid' => $service->uuid,
                'quantity' => 1,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('staff_block_credit_movements', 2);
        $this->assertSame('60000.00', Invoice::query()->sole()->staff_block_credit_used);
        $this->assertSame('40000.00', Invoice::query()->sole()->total_amount);
    }

    public function test_json_emergency_arrival_stays_financially_null_and_opens_care_and_medicine_immediately(): void
    {
        $actor = $this->receptionist(['patients.create', 'episodes.create']);

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'first_name' => 'Urgent',
            'last_name' => 'Patient',
            'birth_date' => '1980-01-01',
            'sex' => 'M',
            'is_emergency' => true,
        ])->assertCreated()
            ->assertJsonPath('episode.priority', 'EMERGENCY')
            ->assertJsonPath('episode.financial_mode', null);

        $episode = Episode::query()->with('orientations')->sole();
        $this->assertNull($episode->financial_mode);
        $this->assertSame(
            [CatalogModule::Care, CatalogModule::Medicine],
            $episode->orientations->pluck('destination_module')->sortBy(fn ($module) => $module->value)->values()->all(),
        );
        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('episode_orientations', 2);
        $this->assertDatabaseCount('invoices', 0);
    }

    /** @param array<int, string> $permissions */
    private function receptionist(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function service(
        User $actor,
        string $code,
        string $name,
        CatalogModule $module,
        string $standardAmount = '20000.00',
        ?string $mutualAmount = null,
        bool $receptionSelectable = true,
        ?ReceptionRoutingMode $routing = ReceptionRoutingMode::MedicineDirect,
        StaffCoveragePolicy $policy = StaffCoveragePolicy::OrdinaryFullCoverage,
    ): CatalogItem {
        $item = CatalogItem::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => CatalogItemType::Service,
            'module' => $module,
            'unit' => 'acte',
            'billable' => true,
            'stockable' => false,
            'reception_selectable' => $receptionSelectable,
            'reception_routing_mode' => $routing,
            'staff_coverage_policy' => $policy,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        CatalogTariff::query()->create([
            'catalog_item_id' => $item->id,
            'tariff_category' => CatalogTariffCategory::Standard,
            'amount' => $standardAmount,
            'currency' => 'MGA',
            'effective_from' => now(),
            'active_key' => 'CURRENT',
            'change_reason' => 'Tarif test parcours Réception',
            'created_by' => $actor->id,
        ]);

        if ($mutualAmount !== null) {
            CatalogTariff::query()->create([
                'catalog_item_id' => $item->id,
                'tariff_category' => CatalogTariffCategory::Mutual,
                'amount' => $mutualAmount,
                'currency' => 'MGA',
                'effective_from' => now(),
                'active_key' => 'CURRENT',
                'change_reason' => 'Tarif mutuelle test parcours Réception',
                'created_by' => $actor->id,
            ]);
        }

        return $item;
    }
}
