<?php

namespace Tests\Feature\Episode;

use App\Actions\Administration\LinkPatientToEmployeeAction;
use App\Actions\Episode\CreateEpisodeAction;
use App\Actions\Episode\SetEpisodeFinancialContextAction;
use App\Enums\CatalogTariffCategory;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodePriority;
use App\Enums\PatientType;
use App\Models\AuditLog;
use App\Models\BillableItem;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\CatalogTariffResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EpisodeFinancialContextTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.code' => 'M']);
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach ([
            'episodes.create', 'episodes.update', 'patient_staff_links.create',
            'mutual_organizations.view', 'employees.patient_lookup',
        ] as $name) {
            $permission = Permission::query()->create(['name' => $name]);
            $role->permissions()->attach($permission);
        }

        $this->actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($this->actor);
    }

    public function test_one_permanent_patient_can_have_self_mutual_staff_then_self_episodes(): void
    {
        // The deliberately contradictory legacy value proves it is not the
        // financial source of truth for any Episode below.
        $patient = $this->patient(PatientType::Mutual);
        $employee = $this->employee();
        app(LinkPatientToEmployeeAction::class)->execute($patient, $employee, $this->actor);
        $firstOrganization = $this->organization('Mutuelle A', '80.00');

        $selfOne = $this->set($this->episode($patient), EpisodeFinancialMode::Self);
        $mutualOne = $this->set(
            $this->episode($patient),
            EpisodeFinancialMode::Mutual,
            $this->mutualContext($firstOrganization, 'A-001'),
        );
        $staff = $this->set(
            $this->episode($patient),
            EpisodeFinancialMode::Staff,
            ['employee_uuid' => $employee->uuid],
        );
        $selfTwo = $this->set($this->episode($patient), EpisodeFinancialMode::Self);

        $this->assertSame(EpisodeFinancialMode::Self, $selfOne->financial_mode);
        $this->assertSame(EpisodeFinancialMode::Mutual, $mutualOne->financial_mode);
        $this->assertSame(EpisodeFinancialMode::Staff, $staff->financial_mode);
        $this->assertSame(EpisodeFinancialMode::Self, $selfTwo->financial_mode);
        $this->assertSame(PatientType::Mutual, $patient->fresh()->patient_type);
        $this->assertSame(
            CatalogTariffCategory::Standard,
            app(CatalogTariffResolver::class)->categoryFor($selfOne),
        );

        $this->assertNull($selfOne->mutualCoverage);
        $this->assertNull($selfOne->staffCoverage);
        $this->assertSame($firstOrganization->id, $mutualOne->mutualCoverage->mutual_organization_id);
        $this->assertSame('Mutuelle A', $mutualOne->mutualCoverage->organization_name_snapshot);
        $this->assertSame('80.00', $mutualOne->mutualCoverage->coverage_rate_snapshot);
        $this->assertSame($employee->id, $staff->staffCoverage->employee_id);

        $this->assertDatabaseCount('mutual_organizations', 1);
        $this->assertDatabaseCount('episode_mutual_coverages', 1);
        $this->assertDatabaseCount('episode_staff_coverages', 1);
        $this->assertDatabaseCount('patient_mutual_coverages', 0);
    }

    public function test_a_legacy_mutual_patient_can_have_a_new_self_episode_priced_as_standard(): void
    {
        $patient = $this->patient(PatientType::Mutual);
        $episode = $this->set($this->episode($patient), EpisodeFinancialMode::Self);

        $this->assertSame(PatientType::Mutual, $patient->fresh()->patient_type);
        $this->assertSame(EpisodeFinancialMode::Self, $episode->financial_mode);
        $this->assertSame(
            CatalogTariffCategory::Standard,
            app(CatalogTariffResolver::class)->categoryFor($episode),
        );
    }

    public function test_a_staff_identity_link_does_not_force_staff_on_another_episode(): void
    {
        $patient = $this->patient();
        $employee = $this->employee();
        app(LinkPatientToEmployeeAction::class)->execute($patient, $employee, $this->actor);

        $staffEpisode = $this->set(
            $this->episode($patient),
            EpisodeFinancialMode::Staff,
            ['employee_uuid' => $employee->uuid],
        );
        $selfEpisode = $this->set($this->episode($patient), EpisodeFinancialMode::Self);

        $this->assertSame(EpisodeFinancialMode::Staff, $staffEpisode->financial_mode);
        $this->assertSame($employee->id, $staffEpisode->staffCoverage->employee_id);
        $this->assertSame(EpisodeFinancialMode::Self, $selfEpisode->financial_mode);
        $this->assertNull($selfEpisode->staffCoverage);
        $this->assertSame(PatientType::Standard, $patient->fresh()->patient_type);
    }

    public function test_two_episodes_of_one_patient_can_snapshot_two_different_mutual_organizations(): void
    {
        $patient = $this->patient();
        $firstOrganization = $this->organization('Mutuelle A', '80.00');
        $secondOrganization = $this->organization('Mutuelle B', '100.00');

        $firstEpisode = $this->set(
            $this->episode($patient),
            EpisodeFinancialMode::Mutual,
            $this->mutualContext($firstOrganization, 'A-001'),
        );
        $secondEpisode = $this->set(
            $this->episode($patient),
            EpisodeFinancialMode::Mutual,
            $this->mutualContext($secondOrganization, 'B-002'),
        );

        $this->assertSame($firstOrganization->id, $firstEpisode->mutualCoverage->mutual_organization_id);
        $this->assertSame('Mutuelle A', $firstEpisode->mutualCoverage->organization_name_snapshot);
        $this->assertSame($secondOrganization->id, $secondEpisode->mutualCoverage->mutual_organization_id);
        $this->assertSame('Mutuelle B', $secondEpisode->mutualCoverage->organization_name_snapshot);
        $this->assertDatabaseCount('mutual_organizations', 2);
        $this->assertDatabaseCount('episode_mutual_coverages', 2);
    }

    public function test_setting_the_same_context_twice_is_idempotent_and_does_not_duplicate_audit(): void
    {
        $episode = $this->set($this->episode($this->patient()), EpisodeFinancialMode::Self);
        $completedAt = $episode->financial_context_completed_at?->toJSON();
        $auditCount = AuditLog::query()->where('action', 'financial_context.set')->count();

        $replayed = $this->set($episode, EpisodeFinancialMode::Self);

        $this->assertSame($completedAt, $replayed->financial_context_completed_at?->toJSON());
        $this->assertSame(
            $auditCount,
            AuditLog::query()->where('action', 'financial_context.set')->count(),
        );
        $this->assertDatabaseCount('episode_mutual_coverages', 0);
        $this->assertDatabaseCount('episode_staff_coverages', 0);
    }

    public function test_mutual_context_requires_access_to_the_mutual_organization_reference(): void
    {
        $role = Role::query()->create(['code' => 'LIMITED', 'name' => 'Limité']);
        $permission = Permission::query()->where('name', 'episodes.create')->firstOrFail();
        $role->permissions()->attach($permission);
        $limitedActor = User::factory()->create(['role_id' => $role->id]);
        $episode = $this->episode($this->patient());

        $this->expectException(AuthorizationException::class);

        app(SetEpisodeFinancialContextAction::class)->execute(
            $episode,
            EpisodeFinancialMode::Mutual,
            $this->mutualContext($this->organization('Mutuelle protégée'), 'P-001'),
            $limitedActor,
        );
    }

    public function test_financial_context_cannot_be_replaced_after_a_billable_item_exists(): void
    {
        $episode = $this->set($this->episode($this->patient()), EpisodeFinancialMode::Self);

        BillableItem::query()->create([
            'episode_id' => $episode->id,
            'source_module' => 'RECEPTION',
            'description' => 'Prestation déjà tarifée',
            'quantity' => '1.00',
            'unit_price' => '10000.00',
            'total_amount' => '10000.00',
            'gross_amount' => '10000.00',
            'coverage_amount' => '0.00',
            'patient_amount' => '10000.00',
            'currency' => 'MGA',
            'status' => 'PENDING',
            'created_by' => $this->actor->id,
        ]);

        try {
            $this->set(
                $episode,
                EpisodeFinancialMode::Mutual,
                $this->mutualContext($this->organization('Mutuelle C'), 'C-003'),
            );
            $this->fail('Le contexte financier facturé ne doit pas pouvoir être remplacé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_mode', $exception->errors());
        }

        $this->assertSame(EpisodeFinancialMode::Self, $episode->fresh()->financial_mode);
        $this->assertDatabaseCount('episode_mutual_coverages', 0);
    }

    public function test_emergency_opens_care_and_medicine_with_a_nullable_financial_mode(): void
    {
        $episode = app(CreateEpisodeAction::class)->execute(
            $this->patient(),
            EpisodePriority::Emergency,
            $this->actor,
        );

        $this->assertNull($episode->financial_mode);
        $this->assertEqualsCanonicalizing(
            ['CARE', 'MEDICINE'],
            $episode->orientations->pluck('destination_module.value')->all(),
        );
    }

    public function test_financial_context_cannot_be_replaced_after_an_invoice_exists(): void
    {
        $episode = $this->set($this->episode($this->patient()), EpisodeFinancialMode::Self);
        Invoice::query()->create([
            'patient_id' => $episode->patient_id,
            'episode_id' => $episode->id,
            'invoice_number' => 'AI-CONTEXT-001',
            'status' => 'DRAFT',
            'currency' => 'MGA',
            'subtotal_amount' => '10000.00',
            'discount_amount' => '0.00',
            'coverage_amount' => '0.00',
            'total_amount' => '10000.00',
            'paid_amount' => '0.00',
            'balance_amount' => '10000.00',
            'created_by' => $this->actor->id,
        ]);

        try {
            $this->set(
                $episode,
                EpisodeFinancialMode::Mutual,
                $this->mutualContext($this->organization('Mutuelle D'), 'D-004'),
            );
            $this->fail('Le contexte financier facturé ne doit pas pouvoir être remplacé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('financial_mode', $exception->errors());
        }

        $this->assertSame(EpisodeFinancialMode::Self, $episode->fresh()->financial_mode);
        $this->assertDatabaseCount('episode_mutual_coverages', 0);
    }

    private function patient(PatientType $type = PatientType::Standard): Patient
    {
        return Patient::query()->create([
            'patient_number' => 'M-'.fake()->unique()->numerify('26-####'),
            'patient_type' => $type,
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-001',
            'first_name' => 'Soa',
            'last_name' => 'Rakoto',
            'sex' => 'F',
            'birth_date' => '1990-01-01',
            'active' => true,
        ]);
    }

    private function organization(string $name, string $rate = '100.00'): MutualOrganization
    {
        return MutualOrganization::query()->create([
            'name' => $name,
            'coverage_rate' => $rate,
            'active' => true,
        ]);
    }

    private function episode(Patient $patient): Episode
    {
        return app(CreateEpisodeAction::class)->execute($patient, actor: $this->actor);
    }

    /** @param array<string, mixed> $context */
    private function set(
        Episode $episode,
        EpisodeFinancialMode $mode,
        array $context = [],
    ): Episode {
        return app(SetEpisodeFinancialContextAction::class)->execute(
            $episode,
            $mode,
            $context,
            $this->actor,
        );
    }

    /** @return array<string, mixed> */
    private function mutualContext(MutualOrganization $organization, string $membership): array
    {
        return [
            'mutual_organization_uuid' => $organization->uuid,
            'employer_name' => 'Entreprise de test',
            'beneficiary_type' => 'PRINCIPAL',
            'membership_number' => $membership,
        ];
    }
}
