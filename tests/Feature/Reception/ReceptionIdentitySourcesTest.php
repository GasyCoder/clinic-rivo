<?php

namespace Tests\Feature\Reception;

use App\Enums\EpisodeFinancialMode;
use App\Enums\HrReferenceType;
use App\Enums\PartnerCategory;
use App\Enums\PartnerProfession;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\Episode;
use App\Models\HrReferenceValue;
use App\Models\PartnerOrganization;
use App\Models\Patient;
use App\Models\PatientStaffLink;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-211 — l'accueil retrouve la personne au lieu de ressaisir son identité :
 * un membre du personnel ou un stagiaire depuis sa fiche RH, un partenaire
 * médical depuis sa fiche. La prise en charge reste un choix du passage
 * (ADR-051) ; un stagiaire n'a jamais la prise en charge Personnel (ADR-194).
 */
class ReceptionIdentitySourcesTest extends TestCase
{
    use RefreshDatabase;

    private const RECEPTION = [
        'episodes.create', 'episodes.update', 'patients.create', 'patients.view',
        'employees.patient_lookup', 'patient_staff_links.create', 'partner_organizations.view',
    ];

    public function test_a_staff_member_is_received_from_the_hr_file_without_retyping(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $employee = $this->employee('EMP-ID-01', 'Rasoa', 'Fara');

        $this->actingAs($actor)->getJson('/reception/employees/patient-lookup?q=EMP-ID')
            ->assertOk()
            ->assertJsonPath('data.0.is_intern', false)
            ->assertJsonPath('data.0.staff_coverage_eligible', true)
            ->assertJsonPath('data.0.can_open_patient_record', true)
            ->assertJsonPath('data.0.birth_date', '1988-03-09')
            ->assertJsonMissingPath('data.0.email')
            ->assertJsonMissingPath('data.0.identity_document_number');

        // L'identité vient de la fiche RH : la ressaisir est refusé.
        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STAFF',
            'employee_uuid' => $employee->uuid,
            'last_name' => 'Autre nom',
        ])->assertUnprocessable()->assertJsonValidationErrors('last_name');

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STAFF',
            'employee_uuid' => $employee->uuid,
        ])->assertCreated();

        $patient = Patient::query()->sole();
        $this->assertSame('Rasoa', $patient->last_name);
        $this->assertSame('1988-03-09', $patient->birth_date->toDateString());
        $this->assertTrue(PatientStaffLink::query()->active()->where('patient_id', $patient->id)->where('employee_id', $employee->id)->exists());
        // Le lien ne choisit aucune prise en charge : elle se décide au passage.
        $this->assertNull(Episode::query()->sole()->financial_mode);

        // La fois suivante, le dossier relié est repris, jamais doublé.
        $this->actingAs($actor)->getJson('/reception/employees/patient-lookup?q=EMP-ID')
            ->assertJsonPath('data.0.linked_patient.uuid', $patient->uuid);
        $this->actingAs($actor)->postJson('/reception/patients', ['patient_uuid' => $patient->uuid])->assertCreated();
        $this->assertSame(1, Patient::query()->count());
        $this->assertSame(2, Episode::query()->count());
    }

    public function test_a_similar_record_is_linked_to_the_hr_file_instead_of_being_doubled(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $employee = $this->employee('EMP-ID-02', 'Rakoto', 'Jean', phone: '0341112233');

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'last_name' => 'Rakoto',
            'first_name' => 'Jean',
            'birth_date' => '1988-03-09',
            'sex' => 'M',
        ])->assertCreated();
        $existing = Patient::query()->sole();

        $duplicates = $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STAFF',
            'employee_uuid' => $employee->uuid,
        ])->assertUnprocessable()->json('duplicates');
        $this->assertSame($existing->uuid, $duplicates[0]['uuid']);

        // « C'est la même personne » : le dossier existant est relié, puis mis à jour depuis la fiche RH.
        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_uuid' => $existing->uuid,
            'employee_uuid' => $employee->uuid,
        ])->assertCreated();

        $this->assertSame(1, Patient::query()->count());
        $this->assertTrue(PatientStaffLink::query()->active()->where('patient_id', $existing->id)->where('employee_id', $employee->id)->exists());
        $this->assertSame('0341112233', $existing->refresh()->phone);

        // Un dossier déjà relié ne se relie pas à un autre employé.
        $other = $this->employee('EMP-ID-03', 'Randria', 'Paul');
        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_uuid' => $existing->uuid,
            'employee_uuid' => $other->uuid,
        ])->assertUnprocessable()->assertJsonValidationErrors('employee_uuid');
    }

    public function test_an_intern_is_found_without_retyping_but_never_covered_as_staff(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $intern = $this->employee('STG-01', 'Soa', 'Nirina');
        $this->internship($intern);

        $this->actingAs($actor)->getJson('/reception/employees/patient-lookup?q=STG-01')
            ->assertOk()
            ->assertJsonPath('data.0.is_intern', true)
            ->assertJsonPath('data.0.staff_coverage_eligible', false)
            ->assertJsonPath('data.0.can_open_patient_record', true);

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STAFF',
            'employee_uuid' => $intern->uuid,
        ])->assertCreated();
        $episode = Episode::query()->sole();

        $this->actingAs($actor)->postJson(route('reception.passages.financial-context.store', $episode), [
            'financial_mode' => EpisodeFinancialMode::Staff->value,
            'employee_uuid' => $intern->uuid,
            'lines' => [],
        ])->assertUnprocessable()->assertJsonPath(
            'errors.employee_uuid.0',
            'Un stagiaire n’a pas droit à la prise en charge Personnel : son passage est au tarif Standard.',
        );

        $this->assertNull($episode->refresh()->financial_mode);

        $this->actingAs($actor)->postJson(route('reception.passages.financial-context.store', $episode), [
            'financial_mode' => EpisodeFinancialMode::Self->value,
            'lines' => [],
        ])->assertOk();
        $this->assertSame(EpisodeFinancialMode::Self, $episode->refresh()->financial_mode);
    }

    public function test_a_partner_is_not_an_identity_source_at_the_reception(): void
    {
        // ADR-211, amendement du 2026-09-28 — un partenaire se choisit à la prise
        // en charge du passage ; l'étape Patient ne le recherche plus.
        $actor = $this->receptionist(self::RECEPTION);
        $doctor = $this->partner(PartnerCategory::Medical, ['last_name' => 'Rabe', 'profession' => PartnerProfession::Doctor]);

        $this->actingAs($actor)->getJson('/reception/partners/patient-lookup?q=Rabe')->assertNotFound();

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'partner_uuid' => $doctor->uuid,
            'last_name' => 'Rabe',
            'birth_date' => '1975-06-01',
            'sex' => 'M',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'partner_uuid' => 'Un partenaire se choisit à l’étape Prise en charge du passage, pas à l’identité du patient.',
        ]);
        $this->assertSame(0, Patient::query()->count());
        $this->assertNull($doctor->refresh()->patient_id);
    }

    public function test_a_record_already_linked_to_a_partner_still_proposes_the_partner_coverage(): void
    {
        $actor = $this->receptionist(self::RECEPTION);

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STANDARD',
            'last_name' => 'Rabe',
            'birth_date' => '1975-06-01',
            'sex' => 'M',
            'reception_draft' => ['designation_deferred' => true, 'catalog_lines' => []],
        ])->assertCreated();
        $episode = Episode::query()->sole();
        // Un lien posé avant l'amendement reste lu : l'étape 5 propose ce partenaire.
        $doctor = $this->partner(PartnerCategory::Medical, ['last_name' => 'Rabe', 'profession' => PartnerProfession::Doctor, 'patient_id' => $episode->patient_id]);

        $this->actingAs($actor)->get(route('reception.passages.journey.show', $episode))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resumeEpisode.patient_links.partner.uuid', $doctor->uuid)
                ->where('resumeEpisode.financial_mode', null));
    }

    public function test_the_coverage_is_proposed_from_the_links_never_saved_for_them(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $employee = $this->employee('EMP-ID-04', 'Vola', 'Mamy');

        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STAFF',
            'employee_uuid' => $employee->uuid,
            'reception_draft' => ['designation_deferred' => true, 'catalog_lines' => []],
        ])->assertCreated();
        $episode = Episode::query()->sole();

        $this->actingAs($actor)->get(route('reception.passages.journey.show', $episode))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Create')
                ->where('resumeEpisode.financial_mode', null)
                ->where('resumeEpisode.patient_links.employee.uuid', $employee->uuid)
                ->where('resumeEpisode.patient_links.employee.staff_coverage_eligible', true)
                ->where('resumeEpisode.patient_links.partner', null));

        $this->assertNull($episode->refresh()->financial_mode);
    }

    /** @param list<string> $permissions */
    private function receptionist(array $permissions): User
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function employee(string $number, string $lastName, string $firstName, ?string $phone = null): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => 'F',
            'birth_date' => '1988-03-09',
            'profession' => 'Infirmière',
            'phone' => $phone,
            'active' => true,
        ]);
    }

    private function internship(Employee $employee): void
    {
        $type = HrReferenceValue::query()->create([
            'type' => HrReferenceType::ContractType->value,
            'code' => 'STAGE',
            'label' => 'Stage',
            'active' => true,
            'metadata' => ['internship' => true],
        ]);

        EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type_id' => $type->id,
            'starts_on' => now()->subWeek()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function partner(PartnerCategory $category, array $attributes): PartnerOrganization
    {
        return PartnerOrganization::query()->create([
            'category' => $category,
            'name' => $attributes['name'] ?? '',
            'active' => true,
            ...$attributes,
        ]);
    }
}
