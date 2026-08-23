<?php

namespace Tests\Feature\Administration;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            ProfessionalProfileSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    private function userWithRole(string $roleCode, array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            ...$attributes,
        ]);
    }

    private function validPayload(string $email = 'collaborateur@clinic.test'): array
    {
        return [
            'name' => 'Rakoto Soa',
            'email' => $email,
            'password' => 'Valid-password1!',
            'password_confirmation' => 'Valid-password1!',
            'role_id' => Role::query()->where('code', 'RECEPTION')->value('id'),
        ];
    }

    private function allow(User $user, array $permissionNames): User
    {
        $permissionIds = Permission::query()->whereIn('name', $permissionNames)->pluck('id');

        $user->permissions()->syncWithoutDetaching(
            $permissionIds->mapWithKeys(fn (int $id) => [$id => ['effect' => 'allow']])->all(),
        );

        return $user->fresh();
    }

    private function siteUserManager(bool $canAssignPermissionOverrides = true): User
    {
        $permissions = [
            'users.view', 'users.create', 'users.update',
            'users.activate', 'users.deactivate', 'roles.assign',
            'permissions.view',
        ];

        if ($canAssignPermissionOverrides) {
            $permissions[] = 'permissions.assign';
        }

        return $this->allow($this->userWithRole('ADMINISTRATION'), $permissions);
    }

    public function test_administration_user_is_confined_to_its_hr_space(): void
    {
        $actor = $this->userWithRole('ADMINISTRATION');

        $this->actingAs($actor)->get('/administration')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Administration/Index'));

        $this->actingAs($actor)->get('/administration/users')->assertForbidden();
        $this->actingAs($actor)->get('/logistics')->assertForbidden();
        $this->actingAs($actor)->get('/reception/visitors')->assertForbidden();
    }

    public function test_super_admin_is_rejected_and_an_authorized_operational_account_can_open_the_local_user_directory(): void
    {
        $superAdmin = $this->userWithRole('SUPER_ADMIN');

        $this->actingAs($superAdmin)->get('/administration/users')
            ->assertRedirect('/login');

        $actor = $this->siteUserManager();

        $this->actingAs($actor)->get('/administration/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Administration/Users/Index')
                ->has('users.data', 1)
                ->where('users.data', fn ($users) => collect($users)->contains('email', $actor->email))
                ->where('roles', fn ($roles) => collect($roles)->doesntContain('code', 'SUPER_ADMIN'))
                ->where('roles', function ($roles) {
                    $nurse = collect($roles)->firstWhere('code', 'NURSE');

                    return $nurse && count($nurse['profiles']) === 3;
                })
                ->has('permissionCatalog'));
    }

    public function test_unprivileged_role_cannot_open_user_administration(): void
    {
        $actor = $this->userWithRole('RECEPTION');

        $this->actingAs($actor)->get('/administration/users')->assertForbidden();
    }

    public function test_authorized_operational_account_can_create_a_normal_role_account_and_the_operation_is_audited(): void
    {
        $actor = $this->siteUserManager();

        $this->actingAs($actor)
            ->post('/administration/users', $this->validPayload())
            ->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'collaborateur@clinic.test')->firstOrFail();

        $this->assertTrue($created->isActive());
        $this->assertSame('RECEPTION', $created->role->code);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'entity_id' => $created->id,
            'action' => 'user.create',
            'module' => 'administration',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'entity_id' => $created->id,
            'action' => 'user.role.assign',
        ]);
    }

    public function test_administration_cannot_assign_the_super_admin_role(): void
    {
        $actor = $this->allow($this->userWithRole('ADMINISTRATION'), [
            'users.create', 'roles.assign',
        ]);
        $payload = $this->validPayload();
        $payload['role_id'] = Role::query()->where('code', 'SUPER_ADMIN')->value('id');

        $this->actingAs($actor)
            ->post('/administration/users', $payload)
            ->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_role_with_professional_profiles_requires_a_matching_profile(): void
    {
        $actor = $this->siteUserManager();
        $nurseRole = Role::query()->where('code', 'NURSE')->firstOrFail();
        $guardProfile = ProfessionalProfile::query()->where('code', 'GUARD')->firstOrFail();
        $payload = [
            ...$this->validPayload('nurse@clinic.test'),
            'role_id' => $nurseRole->id,
        ];

        $this->actingAs($actor)
            ->post('/administration/users', $payload)
            ->assertSessionHasErrors('professional_profile_id');

        $payload['professional_profile_id'] = $guardProfile->id;

        $this->actingAs($actor)
            ->post('/administration/users', $payload)
            ->assertSessionHasErrors('professional_profile_id');

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_two_nurse_accounts_can_have_different_profile_permissions(): void
    {
        $actor = $this->siteUserManager();
        $nurseRole = Role::query()->where('code', 'NURSE')->firstOrFail();
        $nurseProfile = ProfessionalProfile::query()->where('code', 'REGISTERED_NURSE')->firstOrFail();
        $anesthetistProfile = ProfessionalProfile::query()->where('code', 'ANESTHETIST')->firstOrFail();
        $anesthesiaPermission = Permission::query()->where('name', 'anesthesia.validate')->firstOrFail();

        $this->actingAs($actor)->post('/administration/users', [
            ...$this->validPayload('infirmiere@clinic.test'),
            'role_id' => $nurseRole->id,
            'professional_profile_id' => $nurseProfile->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($actor)->post('/administration/users', [
            ...$this->validPayload('anesthesiste@clinic.test'),
            'role_id' => $nurseRole->id,
            'professional_profile_id' => $anesthetistProfile->id,
            'permission_overrides' => [[
                'permission_id' => $anesthesiaPermission->id,
                'effect' => 'allow',
            ]],
        ])->assertSessionHasNoErrors();

        $nurse = User::query()->where('email', 'infirmiere@clinic.test')->firstOrFail();
        $anesthetist = User::query()->where('email', 'anesthesiste@clinic.test')->firstOrFail();

        $this->assertSame($nurse->role_id, $anesthetist->role_id);
        $this->assertFalse($nurse->hasPermissionTo('anesthesia.validate'));
        $this->assertTrue($anesthetist->hasPermissionTo('anesthesia.validate'));
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $anesthetist->id,
            'action' => 'user.profile.assign',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $anesthetist->id,
            'action' => 'user.permissions.assign',
        ]);
    }

    public function test_permission_overrides_require_the_dedicated_permission(): void
    {
        $permission = Permission::query()->where('name', 'patients.view')->firstOrFail();
        $payload = [
            ...$this->validPayload(),
            'permission_overrides' => [[
                'permission_id' => $permission->id,
                'effect' => 'deny',
            ]],
        ];

        $administration = $this->allow($this->userWithRole('ADMINISTRATION'), [
            'users.create', 'roles.assign',
        ]);
        $this->actingAs($administration)
            ->post('/administration/users', $payload)
            ->assertSessionHasErrors('permission_overrides');

        $siteManager = $this->siteUserManager();
        $payload['email'] = 'avec-exception@clinic.test';

        $this->actingAs($siteManager)
            ->post('/administration/users', $payload)
            ->assertSessionHasNoErrors();

        $created = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $created->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $siteManager->id,
            'entity_id' => $created->id,
            'action' => 'user.permissions.assign',
        ]);
    }

    public function test_user_cannot_change_their_own_role_or_individual_permissions(): void
    {
        $actor = $this->siteUserManager();
        $payload = [
            'name' => $actor->name,
            'email' => $actor->email,
            'role_id' => Role::query()->where('code', 'RECEPTION')->value('id'),
            'password' => null,
            'password_confirmation' => null,
        ];

        $this->actingAs($actor)
            ->put("/administration/users/{$actor->uuid}", $payload)
            ->assertSessionHasErrors('role_id');

        $permission = Permission::query()->where('name', 'patients.view')->firstOrFail();
        $payload['role_id'] = $actor->role_id;
        $payload['permission_overrides'] = [[
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]];

        $this->actingAs($actor)
            ->put("/administration/users/{$actor->uuid}", $payload)
            ->assertSessionHasErrors('permission_overrides');
    }

    public function test_deactivation_revokes_access_sessions_and_keeps_an_audited_user_record(): void
    {
        $actor = $this->siteUserManager();
        $target = $this->userWithRole('RECEPTION');
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($actor)
            ->post("/administration/users/{$target->uuid}/deactivate", ['reason' => 'Fin de contrat'])
            ->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertFalse($target->active);
        $this->assertSame($actor->id, $target->deactivated_by);
        $this->assertSame('Fin de contrat', $target->deactivation_reason);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('users', ['id' => $target->id]);

        $log = AuditLog::query()->where('action', 'user.deactivate')->firstOrFail();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($target->id, $log->entity_id);
        $this->assertSame('Fin de contrat', $log->reason);
    }

    public function test_user_cannot_deactivate_their_own_account(): void
    {
        $actor = $this->siteUserManager();

        $this->actingAs($actor)
            ->post("/administration/users/{$actor->uuid}/deactivate", ['reason' => 'Erreur'])
            ->assertSessionHasErrors('user');

        $this->assertTrue($actor->fresh()->active);
    }

    public function test_site_account_manager_cannot_manage_a_central_super_admin_account(): void
    {
        $target = $this->userWithRole('SUPER_ADMIN');
        $delegate = $this->userWithRole('ADMINISTRATION');
        $delegate = $this->allow($delegate, ['users.deactivate', 'users.assign_super_admin']);

        $this->actingAs($delegate->fresh())
            ->post("/administration/users/{$target->uuid}/deactivate", ['reason' => 'Test de garde'])
            ->assertSessionHasErrors('user');

        $this->assertTrue($target->fresh()->active);
    }

    public function test_deactivated_user_can_be_reactivated_without_losing_identity_or_role(): void
    {
        $actor = $this->siteUserManager();
        $target = $this->userWithRole('RECEPTION', [
            'active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Absence',
        ]);

        $this->actingAs($actor)
            ->post("/administration/users/{$target->uuid}/activate")
            ->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertTrue($target->isActive());
        $this->assertSame('RECEPTION', $target->role->code);
        $this->assertNull($target->deactivation_reason);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->id,
            'entity_id' => $target->id,
            'action' => 'user.activate',
        ]);
    }

    public function test_admin_password_reset_revokes_existing_sessions_and_is_audited_without_logging_the_password(): void
    {
        $actor = $this->siteUserManager();
        $target = $this->userWithRole('RECEPTION');
        DB::table('sessions')->insert([
            'id' => 'password-reset-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($actor)->put("/administration/users/{$target->uuid}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $target->role_id,
            'password' => 'Changed-password1!',
            'password_confirmation' => 'Changed-password1!',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Changed-password1!', $target->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'password-reset-session']);

        $log = AuditLog::query()->where('action', 'user.password.reset_by_admin')->firstOrFail();
        $this->assertSame(['password_changed' => true], $log->new_values);
        $this->assertStringNotContainsString('Changed-password1!', json_encode($log->toArray()));
    }

    public function test_user_instance_cannot_be_physically_deleted(): void
    {
        $user = $this->userWithRole('RECEPTION');

        $this->expectException(LogicException::class);
        $user->delete();
    }

    public function test_user_query_cannot_bypass_the_physical_delete_guard(): void
    {
        $user = $this->userWithRole('RECEPTION');

        $this->expectException(LogicException::class);
        User::query()->whereKey($user->id)->delete();
    }
}
