<?php

namespace Tests\Feature\Administration;

use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Administration\EmployeeAccountLinker;
use App\Support\Hr\DefaultJobTitleAccountRoles;
use App\Support\Hr\JobTitleAccountRole;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-199 — une fonction propose le rôle, et s'il le faut le profil, du compte
 * de celui qui l'exerce : réglé dans le module Fonctions, prérempli à la
 * création de l'accès. Une proposition, jamais un droit.
 */
class JobTitleAccountRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
            'rivo.professional_email.domain' => 'cliniquesaintgeorges.mg',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, ProfessionalProfileSeeder::class, HrReferenceSeeder::class]);
        $this->hr = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_the_delivered_job_titles_propose_the_role_the_cdc_gives_them(): void
    {
        $this->assertSame(['role_code' => 'LABORATORY', 'profile_code' => null], $this->proposal('LAB_TECHNICIAN'));
        $this->assertSame(['role_code' => 'NURSE', 'profile_code' => 'REGISTERED_NURSE'], $this->proposal('GENERAL_NURSE'));
        $this->assertSame(['role_code' => 'NURSE', 'profile_code' => 'MIDWIFE'], $this->proposal('MIDWIFE'));
        $this->assertSame(['role_code' => 'SURGERY', 'profile_code' => 'OR_NURSE'], $this->proposal('OPERATING_ROOM_NURSE'));
        $this->assertSame(['role_code' => 'SUPPORT', 'profile_code' => 'GUARD'], $this->proposal('GUARD'));

        // Le CDC ne rattache pas ces fonctions à un rôle : rien n'est inventé.
        $this->assertNull($this->proposal('GARDENER'));
        $this->assertNull($this->proposal('DENTIST'));
    }

    public function test_replaying_the_proposal_never_overwrites_the_clinic_s_decision(): void
    {
        $lab = $this->jobTitle('LAB_TECHNICIAN');
        $lab->forceFill(['metadata' => [JobTitleAccountRole::ROLE_KEY => null, JobTitleAccountRole::PROFILE_KEY => null]])->save();

        $this->assertSame(0, DefaultJobTitleAccountRoles::apply());
        $this->assertNull($this->proposal('LAB_TECHNICIAN'), '« aucun rôle proposé » est une décision');
    }

    public function test_the_functions_module_sets_and_clears_the_proposed_role(): void
    {
        $driver = $this->jobTitle('DRIVER');

        $this->actingAs($this->hr)->get('/administration/job-titles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('roleOptions')
                ->where('roleOptions', fn ($roles) => collect($roles)->pluck('code')->contains('NURSE')
                    && ! collect($roles)->pluck('code')->contains('SUPER_ADMIN')));

        $this->actingAs($this->hr)->put("/administration/job-titles/{$driver->uuid}", [
            'label' => $driver->label, 'code' => $driver->code,
            'account_role_code' => 'SUPPORT', 'account_profile_code' => 'CLEANER',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['role_code' => 'SUPPORT', 'profile_code' => 'CLEANER'], $this->proposal('DRIVER'));

        // Omettre la clé laisse le réglage ; une autre saisie n'y touche pas.
        $this->actingAs($this->hr)->put("/administration/job-titles/{$driver->uuid}", ['label' => 'Chauffeur', 'code' => $driver->code])->assertSessionHasNoErrors();
        $this->assertSame(['role_code' => 'SUPPORT', 'profile_code' => 'CLEANER'], $this->proposal('DRIVER'));

        // Vide : aucun rôle proposé.
        $this->actingAs($this->hr)->put("/administration/job-titles/{$driver->uuid}", ['label' => 'Chauffeur', 'code' => $driver->code, 'account_role_code' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->proposal('DRIVER'));
    }

    public function test_a_profile_must_belong_to_the_proposed_role_and_super_admin_is_never_proposed(): void
    {
        $driver = $this->jobTitle('DRIVER');

        $this->actingAs($this->hr)->put("/administration/job-titles/{$driver->uuid}", [
            'label' => $driver->label, 'code' => $driver->code,
            'account_role_code' => 'LABORATORY', 'account_profile_code' => 'MIDWIFE',
        ])->assertSessionHasErrors('account_profile_code');

        $this->actingAs($this->hr)->put("/administration/job-titles/{$driver->uuid}", [
            'label' => $driver->label, 'code' => $driver->code, 'account_role_code' => 'SUPER_ADMIN',
        ])->assertSessionHasErrors('account_role_code');

        // Un département n'a pas de rôle proposé : la clé est ignorée.
        $department = HrReferenceValue::query()->ofType(HrReferenceType::Department)->where('code', 'LABORATORY')->firstOrFail();
        $this->actingAs($this->hr)->put("/administration/departments/{$department->uuid}", [
            'label' => $department->label, 'code' => $department->code, 'account_role_code' => 'LABORATORY',
        ])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey(JobTitleAccountRole::ROLE_KEY, $department->fresh()->metadata ?? []);
    }

    public function test_the_employees_waiting_for_an_access_carry_their_proposed_role(): void
    {
        $nurse = $this->employee('GENERAL_NURSE');
        $gardener = $this->employee('GARDENER', 'RH-002');
        $profile = ProfessionalProfile::query()->where('code', 'REGISTERED_NURSE')->firstOrFail();

        $pending = collect($this->withHeaders([
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => 'staff_access.view',
        ])->getJson('/api/v1/super-admin/staff-access')->assertOk()->json('data.pending'))->keyBy('uuid');

        $this->assertSame(Role::query()->where('code', 'NURSE')->value('id'), $pending[$nurse->uuid]['proposed_access']['role_id']);
        $this->assertSame($profile->id, $pending[$nurse->uuid]['proposed_access']['profile_id']);
        $this->assertSame('Infirmier généraliste', $pending[$nurse->uuid]['proposed_access']['job_title']);
        $this->assertNull($pending[$gardener->uuid]['proposed_access']);

        // L'assistant de compte reçoit la même proposition pour une fiche reliable.
        $linkable = collect(app(EmployeeAccountLinker::class)->linkableEmployees())->keyBy('uuid');
        $this->assertSame('NURSE', $linkable[$nurse->uuid]['proposed_access']['role_code']);
    }

    /** @return array{role_code: string|null, profile_code: string|null}|null */
    private function proposal(string $code): ?array
    {
        $proposal = (new JobTitleAccountRole)->forJobTitle($this->jobTitle($code));

        return $proposal ? ['role_code' => $proposal['role_code'], 'profile_code' => $proposal['profile_code']] : null;
    }

    private function jobTitle(string $code): HrReferenceValue
    {
        return HrReferenceValue::query()->ofType(HrReferenceType::JobTitle)->where('code', $code)->firstOrFail();
    }

    private function employee(string $jobTitle, string $number = 'RH-001'): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'last_name' => 'RABE',
            'first_name' => 'Hery'.$number,
            'sex' => 'M',
            'active' => true,
            'job_title_id' => $this->jobTitle($jobTitle)->id,
        ]);
    }
}
