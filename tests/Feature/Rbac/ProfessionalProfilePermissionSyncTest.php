<?php

namespace Tests\Feature\Rbac;

use App\Actions\User\UpdateUserAction;
use App\Enums\UserPermissionSource;
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
use Tests\TestCase;

class ProfessionalProfilePermissionSyncTest extends TestCase
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

    public function test_midwife_recommendations_are_profile_permissions_and_enable_maternity(): void
    {
        $midwife = $this->createProfileUser('MIDWIFE', true);
        $maternity = $this->permission('maternity.view');

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $midwife->id,
            'permission_id' => $maternity->id,
            'effect' => 'allow',
            'source' => UserPermissionSource::Profile->value,
            'source_profile_id' => $midwife->professional_profile_id,
        ]);
        $this->assertTrue($midwife->fresh()->hasPermissionTo('maternity.view'));
        $this->actingAs($midwife)->get('/maternity')->assertOk();
    }

    public function test_administration_users_screen_exposes_permission_provenance(): void
    {
        $midwife = $this->createProfileUser('MIDWIFE', true);
        $actor = $this->siteManager();

        $this->actingAs($actor)->get('/administration/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Administration/Users/Index')
                ->where('users.data', fn ($users) => collect($users)
                    ->pipe(fn ($items) => collect($items->firstWhere('uuid', $midwife->uuid)['permission_overrides']))
                    ->contains(fn ($override) => $override['name'] === 'maternity.view'
                        && $override['source'] === UserPermissionSource::Profile->value
                        && $override['source_profile_id'] === $midwife->professional_profile_id
                        && $override['source_profile_name'] === 'Sage-femme')));
    }

    public function test_profile_change_replaces_profile_permissions_and_preserves_manual_permission(): void
    {
        $midwife = $this->createProfileUser('MIDWIFE', true, [
            ['permission_id' => $this->permission('surgery.view')->id, 'effect' => 'allow'],
        ]);

        $this->updateProfile($midwife, 'ANESTHETIST', true, [
            ['permission_id' => $this->permission('surgery.view')->id, 'effect' => 'allow'],
        ]);

        $updated = $midwife->fresh(['professionalProfile']);
        $this->assertSame('ANESTHETIST', $updated->professionalProfile->code);
        $this->assertTrue($updated->hasPermissionTo('care.view'));
        $this->assertFalse($updated->hasPermissionTo('maternity.view'));
        $this->assertTrue($updated->hasPermissionTo('anesthesia.view'));
        $this->assertTrue($updated->hasPermissionTo('surgery.view'));
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $updated->id,
            'permission_id' => $this->permission('surgery.view')->id,
            'source' => UserPermissionSource::Manual->value,
            'source_profile_id' => null,
        ]);
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $updated->id,
            'permission_id' => $this->permission('anesthesia.view')->id,
            'source' => UserPermissionSource::Profile->value,
            'source_profile_id' => $updated->professional_profile_id,
        ]);

        $this->actingAs($updated)->get('/')
            ->assertInertia(fn ($page) => $page
                ->where('permissions', fn ($permissions) => collect($permissions)->contains('care.view')
                    && collect($permissions)->contains('anesthesia.view')
                    && collect($permissions)->contains('surgery.view')
                    && ! collect($permissions)->contains('maternity.view')));
        $this->actingAs($updated)->get('/maternity')->assertForbidden();
        $this->actingAs($updated)->get('/anesthesia')->assertOk();
    }

    public function test_profile_change_without_new_recommendations_removes_old_profile_permissions_only(): void
    {
        $midwife = $this->createProfileUser('MIDWIFE', true, [
            ['permission_id' => $this->permission('surgery.view')->id, 'effect' => 'allow'],
        ]);

        $this->updateProfile($midwife, 'ANESTHETIST', false, [
            ['permission_id' => $this->permission('surgery.view')->id, 'effect' => 'allow'],
        ]);

        $updated = $midwife->fresh();
        $this->assertFalse($updated->hasPermissionTo('maternity.view'));
        $this->assertFalse($updated->hasPermissionTo('anesthesia.view'));
        $this->assertTrue($updated->hasPermissionTo('surgery.view'));
    }

    public function test_manual_permission_identical_to_profile_recommendation_survives_profile_change(): void
    {
        $maternityView = $this->permission('maternity.view');
        $midwife = $this->createProfileUser('MIDWIFE', true, [
            ['permission_id' => $maternityView->id, 'effect' => 'allow'],
        ]);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $midwife->id,
            'permission_id' => $maternityView->id,
            'source' => UserPermissionSource::Manual->value,
        ]);

        $this->updateProfile($midwife, 'ANESTHETIST', true, [
            ['permission_id' => $maternityView->id, 'effect' => 'allow'],
        ]);

        $updated = $midwife->fresh();
        $this->assertTrue($updated->hasPermissionTo('maternity.view'));
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $updated->id,
            'permission_id' => $maternityView->id,
            'source' => UserPermissionSource::Manual->value,
            'source_profile_id' => null,
        ]);
    }

    public function test_profile_permission_can_be_converted_to_manual_and_then_survives_profile_change(): void
    {
        $maternityView = $this->permission('maternity.view');
        $midwife = $this->createProfileUser('MIDWIFE', true);

        $this->updateProfile($midwife, 'MIDWIFE', false, [
            ['permission_id' => $maternityView->id, 'effect' => 'allow'],
        ]);
        $this->updateProfile($midwife, 'ANESTHETIST', false, [
            ['permission_id' => $maternityView->id, 'effect' => 'allow'],
        ]);

        $this->assertTrue($midwife->fresh()->hasPermissionTo('maternity.view'));
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $midwife->id,
            'permission_id' => $maternityView->id,
            'source' => UserPermissionSource::Manual->value,
        ]);
    }

    public function test_manual_deny_is_not_overwritten_by_profile_recommendations(): void
    {
        $delivery = $this->permission('maternity.delivery.manage');
        $midwife = $this->createProfileUser('MIDWIFE', true, [
            ['permission_id' => $delivery->id, 'effect' => 'deny'],
        ]);

        $this->assertFalse($midwife->fresh()->hasPermissionTo('maternity.delivery.manage'));
        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $midwife->id,
            'permission_id' => $delivery->id,
            'effect' => 'deny',
            'source' => UserPermissionSource::Manual->value,
            'source_profile_id' => null,
        ]);
    }

    public function test_legacy_permission_rows_default_to_manual_without_data_loss(): void
    {
        $user = User::factory()->create(['role_id' => Role::query()->where('code', 'NURSE')->value('id')]);
        $permission = $this->permission('surgery.view');

        DB::table('user_permissions')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'source' => UserPermissionSource::Manual->value,
            'source_profile_id' => null,
        ]);
        $this->assertTrue($user->fresh()->hasPermissionTo('surgery.view'));
    }

    public function test_backend_profile_change_removes_old_profile_permissions_even_without_override_payload(): void
    {
        $midwife = $this->createProfileUser('MIDWIFE', true);
        $actor = $this->siteManager();
        $anesthetist = $this->profile('ANESTHETIST');

        app(UpdateUserAction::class)->execute($midwife, [
            'name' => $midwife->name,
            'email' => $midwife->email,
            'role_id' => $midwife->role_id,
            'professional_profile_id' => $anesthetist->id,
        ], $actor);

        $this->assertFalse($midwife->fresh()->hasPermissionTo('maternity.view'));
        $this->assertFalse($midwife->fresh()->hasPermissionTo('anesthesia.view'));
        $this->assertDatabaseHas('audit_logs', [
            'entity_id' => $midwife->id,
            'action' => 'user.profile.permissions.sync',
            'module' => 'administration',
        ]);
    }

    public function test_effective_permission_resolution_remains_deny_then_allow_then_role(): void
    {
        $user = $this->createProfileUser('REGISTERED_NURSE', false, [
            ['permission_id' => $this->permission('care.view')->id, 'effect' => 'deny'],
            ['permission_id' => $this->permission('surgery.view')->id, 'effect' => 'allow'],
        ]);

        $this->assertFalse($user->fresh()->hasPermissionTo('care.view'));
        $this->assertTrue($user->fresh()->hasPermissionTo('surgery.view'));
        $this->assertFalse($user->fresh()->hasPermissionTo('maternity.view'));
    }

    /** @param array<int, array{permission_id: int, effect: string}> $manualOverrides */
    private function createProfileUser(string $profileCode, bool $syncRecommendations, array $manualOverrides = []): User
    {
        $actor = $this->siteManager();
        $profile = $this->profile($profileCode);
        $email = fake()->unique()->safeEmail();

        $this->actingAs($actor)->post('/administration/users', [
            'name' => "Compte {$profile->name}",
            'email' => $email,
            'password' => 'Valid-password1!',
            'password_confirmation' => 'Valid-password1!',
            'role_id' => $profile->role_id,
            'professional_profile_id' => $profile->id,
            'permission_overrides' => $manualOverrides,
            'sync_profile_permissions' => $syncRecommendations,
        ])->assertSessionHasNoErrors();

        return User::query()->where('email', $email)->firstOrFail();
    }

    /** @param array<int, array{permission_id: int, effect: string}> $manualOverrides */
    private function updateProfile(User $user, string $profileCode, bool $syncRecommendations, array $manualOverrides): void
    {
        $actor = $this->siteManager();
        $profile = $this->profile($profileCode);

        $this->actingAs($actor)->put("/administration/users/{$user->uuid}", [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $profile->role_id,
            'professional_profile_id' => $profile->id,
            'permission_overrides' => $manualOverrides,
            'sync_profile_permissions' => $syncRecommendations,
        ])->assertSessionHasNoErrors();
    }

    private function siteManager(): User
    {
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id'),
        ]);
        $ids = Permission::query()->whereIn('name', [
            'users.view', 'users.create', 'users.update', 'roles.assign',
            'permissions.view', 'permissions.assign',
        ])->pluck('id');
        $actor->permissions()->sync($ids->mapWithKeys(fn (int $id) => [
            $id => ['effect' => 'allow', 'source' => UserPermissionSource::Manual->value],
        ])->all());

        return $actor->fresh();
    }

    private function profile(string $code): ProfessionalProfile
    {
        return ProfessionalProfile::query()->where('code', $code)->firstOrFail();
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->where('name', $name)->firstOrFail();
    }
}
