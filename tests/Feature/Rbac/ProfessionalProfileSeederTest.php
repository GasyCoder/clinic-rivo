<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfessionalProfileSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedProfiles(): void
    {
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ProfessionalProfileSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_professional_profiles_are_attached_to_the_expected_roles(): void
    {
        $this->seedProfiles();

        $this->assertSame(
            ['ANESTHETIST', 'MIDWIFE', 'REGISTERED_NURSE'],
            Role::query()->where('code', 'NURSE')->firstOrFail()
                ->professionalProfiles()->orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame(
            ['CLEANER', 'GUARD'],
            Role::query()->where('code', 'SUPPORT')->firstOrFail()
                ->professionalProfiles()->orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame(
            ['IT_TECHNICIAN'],
            Role::query()->where('code', 'MAINTENANCE')->firstOrFail()
                ->professionalProfiles()->pluck('code')->all(),
        );
    }

    public function test_profile_recommendations_do_not_authorize_every_account_in_the_role(): void
    {
        $this->seedProfiles();

        $nurseRole = Role::query()->where('code', 'NURSE')->firstOrFail();
        $anesthetistProfile = ProfessionalProfile::query()->where('code', 'ANESTHETIST')->firstOrFail();
        $registeredNurseProfile = ProfessionalProfile::query()->where('code', 'REGISTERED_NURSE')->firstOrFail();
        $anesthetist = User::factory()->create([
            'role_id' => $nurseRole->id,
            'professional_profile_id' => $anesthetistProfile->id,
        ]);
        $registeredNurse = User::factory()->create([
            'role_id' => $nurseRole->id,
            'professional_profile_id' => $registeredNurseProfile->id,
        ]);

        $this->assertFalse($anesthetist->hasPermissionTo('anesthesia.validate'));
        $this->assertFalse($registeredNurse->hasPermissionTo('anesthesia.validate'));

        $anesthetist->permissions()->syncWithoutDetaching(
            $anesthetistProfile->recommendedPermissions
                ->mapWithKeys(fn (Permission $permission) => [
                    $permission->id => ['effect' => 'allow'],
                ])
                ->all(),
        );

        $this->assertTrue($anesthetist->fresh()->hasPermissionTo('anesthesia.validate'));
        $this->assertFalse($anesthetist->fresh()->hasPermissionTo('surgery.view'));
        $this->assertFalse($anesthetistProfile->recommendedPermissions->contains('name', 'surgery.view'));
        $this->assertFalse($registeredNurse->fresh()->hasPermissionTo('anesthesia.validate'));
    }

    public function test_midwife_recommends_maternity_without_changing_the_nurse_role_baseline(): void
    {
        $this->seedProfiles();
        $midwife = ProfessionalProfile::query()->where('code', 'MIDWIFE')->firstOrFail();
        $recommendations = $midwife->recommendedPermissions->pluck('name');

        $this->assertContains('maternity.view', $recommendations);
        $this->assertContains('maternity.delivery.manage', $recommendations);
        $this->assertNotContains('care.view', $recommendations);
        $this->assertNotContains('surgery.intervention.create', $recommendations);
        $this->assertTrue(Role::query()->where('code', 'NURSE')->firstOrFail()->permissions->contains('name', 'care.view'));
        $this->assertFalse(Role::query()->where('code', 'NURSE')->firstOrFail()->permissions->contains('name', 'maternity.view'));
    }

    public function test_legacy_guard_accounts_are_moved_without_losing_their_individual_access(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);

        $legacyGuardRole = Role::query()->create([
            'code' => 'GUARD',
            'name' => 'Gardien',
        ]);
        $permission = Permission::query()->where('name', 'guarding.entries.create')->firstOrFail();
        $legacyGuardRole->permissions()->attach($permission);
        $guard = User::factory()->create(['role_id' => $legacyGuardRole->id]);

        $this->seed(ProfessionalProfileSeeder::class);

        $guard->refresh()->load(['role', 'professionalProfile']);

        $this->assertSame('SUPPORT', $guard->role->code);
        $this->assertSame('GUARD', $guard->professionalProfile->code);
        $this->assertTrue($guard->hasPermissionTo('guarding.entries.create'));
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $guard->id,
            'permission_id' => $permission->id,
            'effect' => 'allow',
        ]);
        $this->assertDatabaseMissing('roles', ['code' => 'GUARD']);
    }
}
