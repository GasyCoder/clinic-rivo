<?php

namespace Tests\Feature\Administration;

use App\Enums\HrReferenceType;
use App\Enums\PlanningShiftKind;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\PlanningShift;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-194 — fonctions par département, photo 4 × 4, stages et planning de garde.
 */
class HrDepartmentsPhotosInternshipsPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $administration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->administration = User::factory()->create([
            'role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id'),
        ]);
    }

    // --- Fonctions par département ---------------------------------------------

    public function test_the_seeded_mapping_links_each_delivered_function_to_its_departments(): void
    {
        $this->assertSame(['Laboratoire'], $this->ref(HrReferenceType::JobTitle, 'Laborantin')->departments->pluck('label')->all());
        $this->assertSame(['Support'], $this->ref(HrReferenceType::JobTitle, 'Gardien')->departments->pluck('label')->all());
        $this->assertEqualsCanonicalizing(
            ['Médecine', 'Chirurgie', 'Maternité'],
            $this->ref(HrReferenceType::JobTitle, 'Infirmier généraliste')->departments->pluck('label')->all(),
        );
    }

    public function test_a_function_outside_the_chosen_department_is_refused_by_the_server(): void
    {
        $this->actingAs($this->administration)
            ->post('/administration/employees', $this->employeePayload('Laboratoire', 'Gardien'))
            ->assertSessionHasErrors('job_title_uuid');
        $this->assertDatabaseCount('employees', 0);

        $this->actingAs($this->administration)
            ->post('/administration/employees', $this->employeePayload('Laboratoire', 'Laborantin'))
            ->assertSessionHasNoErrors();
        $this->assertSame('Laborantin', Employee::query()->firstOrFail()->jobTitle->label);
    }

    public function test_a_function_linked_to_no_department_stays_allowed_everywhere(): void
    {
        $shared = HrReferenceValue::query()->create(['type' => HrReferenceType::JobTitle, 'code' => 'SECRETARY', 'label' => 'Secrétaire', 'active' => true]);

        $this->actingAs($this->administration)
            ->post('/administration/employees', [...$this->employeePayload('Laboratoire', null), 'job_title_uuid' => $shared->uuid])
            ->assertSessionHasNoErrors();
    }

    public function test_an_existing_incoherent_pair_is_kept_until_one_of_the_two_changes(): void
    {
        $employee = Employee::query()->create([
            'employee_number' => 'HIST-001', 'last_name' => 'Rakoto', 'sex' => 'M', 'active' => true,
            'department_id' => $this->ref(HrReferenceType::Department, 'Médecine')->id,
            'job_title_id' => $this->ref(HrReferenceType::JobTitle, 'Assistant Dentisterie')->id,
        ]);
        $payload = [
            ...$this->employeePayload('Médecine', 'Assistant Dentisterie'),
            'employee_number' => 'HIST-001', 'last_name' => 'Rakoto', 'sex' => 'M', 'phone' => '0340000001',
        ];

        // Corriger le téléphone ne force pas à rectifier l'histoire du dossier.
        $this->actingAs($this->administration)->put("/administration/employees/{$employee->uuid}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('0340000001', $employee->refresh()->phone);

        // Changer le département exige un couple cohérent.
        $this->actingAs($this->administration)
            ->put("/administration/employees/{$employee->uuid}", [...$payload, 'department_uuid' => $this->ref(HrReferenceType::Department, 'Laboratoire')->uuid])
            ->assertSessionHasErrors('job_title_uuid');
    }

    public function test_the_functions_module_links_a_function_to_departments_with_an_audit_and_omitting_keeps_the_links(): void
    {
        // ADR-188 — la correspondance se règle dans le module Fonctions.
        $guard = $this->ref(HrReferenceType::JobTitle, 'Gardien');
        $base = ['code' => 'GUARD', 'label' => 'Gardien', 'active' => true, 'position' => 0];

        $this->actingAs($this->administration)->put("/administration/job-titles/{$guard->uuid}", [
            ...$base,
            'department_uuids' => [$this->ref(HrReferenceType::Department, 'Support')->uuid, $this->ref(HrReferenceType::Department, 'Administration')->uuid],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['Support', 'Administration'], $guard->refresh()->departments->pluck('label')->all());
        $this->assertTrue(AuditLog::query()->where('action', 'hr_reference.departments.update')->where('entity_uuid', $guard->uuid)->exists());

        $this->actingAs($this->administration)->put("/administration/job-titles/{$guard->uuid}", [...$base, 'label' => 'Gardien de nuit'])->assertSessionHasNoErrors();
        $this->assertCount(2, $guard->refresh()->departments);

        $this->actingAs($this->administration)->put("/administration/job-titles/{$guard->uuid}", [...$base, 'department_uuids' => []])->assertSessionHasNoErrors();
        $this->assertCount(0, $guard->refresh()->departments);
    }

    public function test_a_new_function_is_created_with_its_departments_and_a_department_cannot_carry_any(): void
    {
        $laboratory = $this->ref(HrReferenceType::Department, 'Laboratoire');

        $this->actingAs($this->administration)->post('/administration/job-titles', [
            'label' => 'Technicien de biologie', 'department_uuids' => [$laboratory->uuid],
        ])->assertSessionHasNoErrors();

        $created = HrReferenceValue::query()->ofType(HrReferenceType::JobTitle)->where('label', 'Technicien de biologie')->firstOrFail();
        $this->assertSame([$laboratory->uuid], $created->departments->pluck('uuid')->all());

        // Un département ne porte pas de départements : la clé est ignorée.
        $this->actingAs($this->administration)->post('/administration/departments', [
            'label' => 'Kinésithérapie', 'department_uuids' => [$laboratory->uuid],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('hr_job_title_departments', ['job_title_id' => HrReferenceValue::query()->where('label', 'Kinésithérapie')->value('id')]);

        // Un département archivé n'est plus proposé à une fonction.
        $laboratory->delete();
        $this->actingAs($this->administration)->put("/administration/job-titles/{$created->uuid}", [
            'label' => 'Technicien de biologie', 'code' => $created->code, 'department_uuids' => [$laboratory->uuid],
        ])->assertSessionHasErrors('department_uuids.0');
    }

    public function test_the_structure_modules_show_where_each_function_exists(): void
    {
        $laboratory = $this->ref(HrReferenceType::Department, 'Laboratoire');

        $this->actingAs($this->administration)->get('/administration/job-titles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/HrStructure/Index')
                ->where('items', fn ($items) => collect($items)->firstWhere('label', 'Laborantin')['departments'][0]['uuid'] === $laboratory->uuid)
                ->where('departmentOptions', fn ($options) => collect($options)->contains('uuid', $laboratory->uuid)));

        $this->actingAs($this->administration)->get('/administration/departments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items', fn ($items) => in_array('Laborantin', collect(collect($items)->firstWhere('label', 'Laboratoire')['job_titles'])->all(), true)));
    }

    public function test_the_import_refuses_a_function_outside_its_department(): void
    {
        $csv = implode("\n", [
            'MATRICULE,NOM,GENRE,FONCTION,DEPARTEMENT',
            'IMP-001,Rabe,Femme,Gardien,Laboratoire',
        ]);

        $this->actingAs($this->administration)->post('/administration/employees/import', [
            'file' => UploadedFile::fake()->createWithContent('personnel.csv', $csv),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('employees', ['employee_number' => 'IMP-001']);
    }

    public function test_the_form_serves_each_function_with_its_departments(): void
    {
        $this->actingAs($this->administration)->get('/administration/employees/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Employees/Create')
                ->where('jobTitles', fn ($titles) => collect($titles)->firstWhere('label', 'Laborantin')['department_uuids']
                    === [$this->ref(HrReferenceType::Department, 'Laboratoire')->uuid]));
    }

    // --- Photo 4 × 4 ----------------------------------------------------------------

    public function test_a_photo_is_stored_privately_squared_and_served_only_to_authorized_accounts(): void
    {
        Storage::fake('local');

        $this->actingAs($this->administration)->post('/administration/employees', [
            ...$this->employeePayload('Laboratoire', 'Laborantin'),
            'photo' => UploadedFile::fake()->image('portrait.jpg', 800, 1000),
        ])->assertSessionHasNoErrors();

        $employee = Employee::query()->firstOrFail();
        $this->assertNotNull($employee->photo_path);
        $this->assertNotNull($employee->photo_updated_at);
        Storage::disk('local')->assertExists($employee->photo_path);
        $this->assertStringStartsWith('hr/employee-photos/', $employee->photo_path);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($employee->photo_path));
        $this->assertSame([600, 600], [$width, $height]);

        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}/photo")
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);
        $this->actingAs($reception)->get("/administration/employees/{$employee->uuid}/photo")->assertForbidden();

        $this->actingAs($this->administration)->get("/administration/employees/{$employee->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('employee.photo_url', fn ($url) => str_contains((string) $url, "/administration/employees/{$employee->uuid}/photo")));
    }

    public function test_a_new_photo_replaces_the_old_file_and_removing_it_deletes_the_file(): void
    {
        Storage::fake('local');
        $this->actingAs($this->administration)->post('/administration/employees', [
            ...$this->employeePayload('Laboratoire', 'Laborantin'),
            'photo' => UploadedFile::fake()->image('a.jpg', 400, 400),
        ]);
        $employee = Employee::query()->firstOrFail();
        $first = $employee->photo_path;

        $this->actingAs($this->administration)->post("/administration/employees/{$employee->uuid}", [
            ...$this->employeePayload('Laboratoire', 'Laborantin'), '_method' => 'put',
            'photo' => UploadedFile::fake()->image('b.png', 500, 500),
        ])->assertSessionHasNoErrors();
        $second = $employee->refresh()->photo_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->actingAs($this->administration)->put("/administration/employees/{$employee->uuid}", [
            ...$this->employeePayload('Laboratoire', 'Laborantin'), 'remove_photo' => true,
        ])->assertSessionHasNoErrors();
        $this->assertNull($employee->refresh()->photo_path);
        Storage::disk('local')->assertMissing($second);
    }

    public function test_a_photo_that_is_too_small_or_not_an_image_is_refused_and_leaves_no_file(): void
    {
        Storage::fake('local');

        $this->actingAs($this->administration)->post('/administration/employees', [
            ...$this->employeePayload('Laboratoire', 'Laborantin'),
            'photo' => UploadedFile::fake()->image('tiny.jpg', 60, 60),
        ])->assertSessionHasErrors('photo');
        $this->actingAs($this->administration)->post('/administration/employees', [
            ...$this->employeePayload('Laboratoire', 'Laborantin'),
            'photo' => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('photo');

        $this->assertDatabaseCount('employees', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('hr/employee-photos'));
    }

    public function test_a_failed_creation_does_not_leave_the_photo_behind(): void
    {
        Storage::fake('local');

        // Le couple est refusé par l'action, après l'écriture de la photo.
        $this->actingAs($this->administration)->post('/administration/employees', [
            ...$this->employeePayload('Laboratoire', 'Gardien'),
            'photo' => UploadedFile::fake()->image('a.jpg', 400, 400),
        ])->assertSessionHasErrors('job_title_uuid');

        $this->assertSame([], Storage::disk('local')->allFiles('hr/employee-photos'));
    }

    // --- Stages -----------------------------------------------------------------------

    public function test_an_internship_contract_requires_its_field_and_keeps_school_level_and_supervisor(): void
    {
        $intern = $this->employee('STG-001', 'Ravelo');
        $supervisor = $this->employee('EMP-001', 'Randria');
        $payload = [
            'employee_uuid' => $intern->uuid,
            'contract_type_uuid' => $this->ref(HrReferenceType::ContractType, 'Stagiaire')->uuid,
            'starts_on' => now()->subWeek()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ];

        $this->actingAs($this->administration)->post('/administration/contracts', $payload)
            ->assertSessionHasErrors('internship_field_uuid');
        $this->actingAs($this->administration)->post('/administration/contracts', [
            ...$payload,
            'internship_field_uuid' => $this->ref(HrReferenceType::InternshipField, 'Sage-femme')->uuid,
            'internship_supervisor_uuid' => $intern->uuid,
        ])->assertSessionHasErrors('internship_supervisor_uuid');

        $this->actingAs($this->administration)->post('/administration/contracts', [
            ...$payload,
            'internship_field_uuid' => $this->ref(HrReferenceType::InternshipField, 'Sage-femme')->uuid,
            'internship_school' => ' Institut   paramédical ',
            'internship_level' => '3e année',
            'internship_supervisor_uuid' => $supervisor->uuid,
        ])->assertSessionHasNoErrors();

        $contract = EmploymentContract::query()->firstOrFail();
        $this->assertTrue($contract->isInternship());
        $this->assertSame('Sage-femme', $contract->internshipField->label);
        $this->assertSame('Institut paramédical', $contract->internship_school);
        $this->assertSame($supervisor->id, $contract->internship_supervisor_id);

        // Passer à un CDI efface le stage : pas de filière sur un CDI.
        $this->actingAs($this->administration)->put("/administration/contracts/{$contract->uuid}", [
            ...$payload,
            'contract_type_uuid' => $this->ref(HrReferenceType::ContractType, 'CDI')->uuid,
            'internship_field_uuid' => $this->ref(HrReferenceType::InternshipField, 'Sage-femme')->uuid,
            'internship_school' => 'Institut',
        ])->assertSessionHasNoErrors();
        $contract->refresh();
        $this->assertNull($contract->internship_field_id);
        $this->assertNull($contract->internship_school);
        $this->assertNull($contract->internship_supervisor_id);
    }

    public function test_new_intern_creates_the_file_then_opens_the_internship_contract(): void
    {
        $this->actingAs($this->administration)->get('/administration/employees/create?stagiaire=1')
            ->assertInertia(fn (Assert $page) => $page->where('internshipIntent', true));

        $response = $this->actingAs($this->administration)->post('/administration/employees', [
            ...$this->employeePayload('Maternité', 'Sage-femme'),
            'employee_number' => 'STG-2026-001',
            'after' => 'internship',
        ])->assertSessionHasNoErrors();

        $intern = Employee::query()->where('employee_number', 'STG-2026-001')->firstOrFail();
        $response->assertRedirect(route('administration.contracts.create', ['employee' => $intern->uuid, 'type' => 'stage']));

        $internshipType = $this->ref(HrReferenceType::ContractType, 'Stagiaire');
        $this->actingAs($this->administration)->get("/administration/contracts/create?employee={$intern->uuid}&type=stage")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedEmployeeUuid', $intern->uuid)
                ->where('selectedContractTypeUuid', $internshipType->uuid)
                ->where('internshipFields', fn ($fields) => collect($fields)->pluck('label')->contains('Infirmier')));
    }

    public function test_the_internships_page_lists_internships_by_status_and_field_and_the_directory_flags_interns(): void
    {
        $intern = $this->employee('STG-001', 'Ravelo');
        $former = $this->employee('STG-000', 'Rabe');
        $staff = $this->employee('EMP-001', 'Randria');
        $this->internship($intern, 'Sage-femme', now()->subWeek(), now()->addMonth());
        $this->internship($former, 'Infirmier', now()->subYear(), now()->subMonths(6));
        EmploymentContract::query()->create([
            'employee_id' => $staff->id,
            'contract_type_id' => $this->ref(HrReferenceType::ContractType, 'CDI')->id,
            'starts_on' => now()->subYear(),
        ]);

        $this->actingAs($this->administration)->get('/administration/internships')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Internships/Index')
                ->has('internships.data', 1)
                ->where('internships.data.0.employee.uuid', $intern->uuid)
                ->where('internships.data.0.internship.field', 'Sage-femme')
                ->where('counts.current', 1)->where('counts.ended', 1)->where('counts.all', 2)
                ->where('hasInternshipType', true));

        $this->actingAs($this->administration)
            ->get('/administration/internships?status=all&field='.$this->ref(HrReferenceType::InternshipField, 'Infirmier')->uuid)
            ->assertInertia(fn (Assert $page) => $page->has('internships.data', 1)->where('internships.data.0.employee.uuid', $former->uuid));

        $this->actingAs($this->administration)->get('/administration/employees')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data', fn ($rows) => collect($rows)->firstWhere('uuid', $intern->uuid)['is_intern'] === true
                && collect($rows)->firstWhere('uuid', $former->uuid)['is_intern'] === false
                && collect($rows)->firstWhere('uuid', $staff->uuid)['is_intern'] === false));

        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);
        $this->actingAs($reception)->get('/administration/internships')->assertForbidden();
    }

    public function test_a_contract_type_is_marked_as_internship_in_settings(): void
    {
        $cdd = $this->ref(HrReferenceType::ContractType, 'CDD');

        $this->actingAs($this->administration)->put("/administration/settings/{$cdd->uuid}", [
            'type' => 'CONTRACT_TYPE', 'code' => 'CDD', 'label' => 'CDD', 'active' => true, 'position' => 1,
            'metadata' => ['internship' => true],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($cdd->refresh()->isInternshipContractType());
    }

    // --- Planning de garde ----------------------------------------------------------

    public function test_on_call_shifts_are_a_second_planning_read_as_a_calendar(): void
    {
        $employee = $this->employee('EMP-001', 'Rakoto');
        $monday = now()->startOfWeek();

        // Sans type : du service, comme avant.
        $this->actingAs($this->administration)->post('/administration/planning', [
            'employee_uuid' => $employee->uuid,
            'starts_at' => $monday->copy()->setTime(7, 0)->toDateTimeString(),
            'ends_at' => $monday->copy()->setTime(15, 0)->toDateTimeString(),
        ])->assertSessionHasNoErrors();
        // Une garde de nuit commencée la veille : elle chevauche la semaine.
        $this->actingAs($this->administration)->post('/administration/planning', [
            'employee_uuid' => $employee->uuid,
            'kind' => 'ON_CALL',
            'title' => 'Garde de nuit',
            'starts_at' => $monday->copy()->subDay()->setTime(19, 0)->toDateTimeString(),
            'ends_at' => $monday->copy()->setTime(7, 0)->toDateTimeString(),
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('administration.planning.index', ['kind' => 'ON_CALL', 'date' => $monday->copy()->subDay()->toDateString()]));

        $this->assertSame(PlanningShiftKind::Shift, PlanningShift::query()->oldest('id')->first()->kind);
        $this->assertSame(PlanningShiftKind::OnCall, PlanningShift::query()->latest('id')->first()->kind);

        $this->actingAs($this->administration)->get('/administration/planning?kind=ON_CALL&view=week&date='.$monday->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Planning/Index')
                ->has('shifts', 1)
                ->where('shifts.0.kind', 'ON_CALL')
                ->where('counts.SHIFT', 1)->where('counts.ON_CALL', 1)->where('counts.ALL', 2)
                ->where('range.from', $monday->toDateString())
                ->where('range.to', $monday->copy()->endOfWeek()->toDateString()));

        $this->actingAs($this->administration)->get('/administration/planning?view=month&kind=ALL&date='.$monday->toDateString())
            ->assertInertia(fn (Assert $page) => $page->has('shifts', 2)
                ->where('range.from', $monday->copy()->startOfMonth()->startOfWeek()->toDateString()));

        // Une valeur inconnue ne casse rien : planning du personnel, semaine.
        $this->actingAs($this->administration)->get('/administration/planning?kind=X&view=Y')
            ->assertInertia(fn (Assert $page) => $page->where('filters.kind', 'SHIFT')->where('filters.view', 'week'));
    }

    public function test_the_create_form_takes_the_day_and_kind_from_the_calendar(): void
    {
        $this->actingAs($this->administration)->get('/administration/planning/create?kind=ON_CALL&date=2026-09-25')
            ->assertInertia(fn (Assert $page) => $page
                ->where('preset.kind', 'ON_CALL')
                ->where('preset.date', '2026-09-25')
                ->has('kinds', 2));

        $this->actingAs($this->administration)->post('/administration/planning', [
            'employee_uuid' => $this->employee('EMP-009', 'Rabe')->uuid,
            'kind' => 'NIGHT',
            'starts_at' => '2026-09-25 19:00:00',
            'ends_at' => '2026-09-26 07:00:00',
        ])->assertSessionHasErrors('kind');
    }

    // --- Outils -----------------------------------------------------------------------

    private function ref(HrReferenceType $type, string $label): HrReferenceValue
    {
        return HrReferenceValue::query()->where('type', $type->value)->where('label', $label)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function employeePayload(?string $department, ?string $jobTitle): array
    {
        return [
            'employee_number' => 'RH-'.str()->upper(str()->random(6)),
            'last_name' => 'Rasoa',
            'first_name' => 'Voahangy',
            'sex' => 'F',
            'active' => true,
            'department_uuid' => $department ? $this->ref(HrReferenceType::Department, $department)->uuid : null,
            'job_title_uuid' => $jobTitle ? $this->ref(HrReferenceType::JobTitle, $jobTitle)->uuid : null,
        ];
    }

    private function employee(string $number, string $lastName): Employee
    {
        return Employee::query()->create(['employee_number' => $number, 'last_name' => $lastName, 'sex' => 'F', 'active' => true]);
    }

    private function internship(Employee $employee, string $field, $startsOn, $endsOn): EmploymentContract
    {
        return EmploymentContract::query()->create([
            'employee_id' => $employee->id,
            'contract_type_id' => $this->ref(HrReferenceType::ContractType, 'Stagiaire')->id,
            'internship_field_id' => $this->ref(HrReferenceType::InternshipField, $field)->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }
}
