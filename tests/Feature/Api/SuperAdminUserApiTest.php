<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuperAdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_remote_super_admin_can_list_users_roles_and_permission_catalog(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        User::factory()->create(['role_id' => $receptionRoleId, 'name' => 'Florent']);

        $this->withHeaders($this->headers(permissions: ['users.view']))
            ->getJson('/api/v1/super-admin/users')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Florent'])
            ->assertJsonPath('meta.site.code', 'A');
    }

    public function test_remote_super_admin_can_create_a_user_with_role_and_individual_permission_override(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $permissionId = Permission::query()->where('name', 'billing.print')->value('id');
        $actorUuid = (string) Str::uuid();

        $response = $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), [
            'users.create', 'roles.assign', 'permissions.assign',
        ]))->postJson('/api/v1/super-admin/users', [
            'name' => 'Andry Rakoto',
            'email' => 'andry@example.test',
            'password' => 'Correct-Horse-Battery-9!',
            'password_confirmation' => 'Correct-Horse-Battery-9!',
            'role_id' => $receptionRoleId,
            'permission_overrides' => [
                ['permission_id' => $permissionId, 'effect' => 'deny'],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Andry Rakoto');
        $user = User::query()->where('email', 'andry@example.test')->sole();
        $this->assertSame('deny', $user->permissions->firstWhere('id', $permissionId)->pivot->effect);

        // The remote actor has no local user row — the audit trail must
        // still capture who did it, via the external actor identity.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.create',
            'external_actor_uuid' => $actorUuid,
            'user_id' => null,
        ]);
    }

    public function test_remote_super_admin_can_create_a_user_without_a_password_and_an_invitation_is_sent_instead(): void
    {
        Notification::fake();
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');

        $response = $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.create', 'roles.assign']))
            ->postJson('/api/v1/super-admin/users', [
                'name' => 'Nirina Rasoa',
                'email' => 'nirina@example.test',
                'role_id' => $receptionRoleId,
            ]);

        $response->assertCreated();
        $user = User::query()->where('email', 'nirina@example.test')->sole();
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.invite.sent',
            'entity_id' => $user->id,
        ]);
    }

    public function test_remote_super_admin_can_deactivate_a_user_with_external_attribution(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $user = User::factory()->create(['role_id' => $receptionRoleId]);
        $actorUuid = (string) Str::uuid();

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['users.deactivate']))
            ->postJson("/api/v1/super-admin/users/{$user->uuid}/deactivate", ['reason' => 'Départ de la clinique'])
            ->assertOk();

        $user->refresh();
        $this->assertFalse($user->active);
        $this->assertNull($user->deactivated_by);
        $this->assertSame($actorUuid, $user->external_deactivated_by_uuid);
        $this->assertSame('Direction centrale', $user->external_deactivated_by_name);
    }

    public function test_remote_super_admin_can_bulk_deactivate_several_users_atomically(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $first = User::factory()->create(['role_id' => $receptionRoleId]);
        $second = User::factory()->create(['role_id' => $receptionRoleId]);
        $actorUuid = (string) Str::uuid();

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['users.deactivate']))
            ->postJson('/api/v1/super-admin/users/bulk/deactivate', [
                'uuids' => [$first->uuid, $second->uuid],
                'reason' => 'Réorganisation du service',
            ])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);

        $this->assertFalse($first->fresh()->active);
        $this->assertFalse($second->fresh()->active);
        $this->assertSame($actorUuid, $first->fresh()->external_deactivated_by_uuid);

        // One unknown uuid in the batch must refuse the whole thing —
        // no partial deactivation.
        $third = User::factory()->create(['role_id' => $receptionRoleId]);

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.deactivate']))
            ->postJson('/api/v1/super-admin/users/bulk/deactivate', [
                'uuids' => [$third->uuid, (string) Str::uuid()],
                'reason' => 'Lot invalide',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('uuids');

        $this->assertTrue($third->fresh()->active);
    }

    public function test_remote_super_admin_can_force_delete_a_never_used_account(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $user = User::factory()->create(['role_id' => $receptionRoleId, 'last_login_at' => null]);

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.force_delete']))
            ->deleteJson("/api/v1/super-admin/users/{$user->uuid}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.force_delete']);
    }

    public function test_remote_super_admin_can_bulk_force_delete_several_never_used_accounts_atomically(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $first = User::factory()->create(['role_id' => $receptionRoleId, 'last_login_at' => null]);
        $second = User::factory()->create(['role_id' => $receptionRoleId, 'last_login_at' => null]);

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.force_delete']))
            ->postJson('/api/v1/super-admin/users/bulk/force-delete', ['uuids' => [$first->uuid, $second->uuid]])
            ->assertOk()
            ->assertJsonPath('data.processed', 2);

        $this->assertDatabaseMissing('users', ['id' => $first->id]);
        $this->assertDatabaseMissing('users', ['id' => $second->id]);

        // One already-used account in the batch must refuse the whole
        // thing — no partial deletion.
        $clean = User::factory()->create(['role_id' => $receptionRoleId, 'last_login_at' => null]);
        $used = User::factory()->create(['role_id' => $receptionRoleId, 'last_login_at' => now()]);

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.force_delete']))
            ->postJson('/api/v1/super-admin/users/bulk/force-delete', ['uuids' => [$clean->uuid, $used->uuid]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $clean->id]);
        $this->assertDatabaseHas('users', ['id' => $used->id]);
    }

    public function test_force_delete_is_refused_for_a_user_who_ever_logged_in(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $user = User::factory()->create(['role_id' => $receptionRoleId, 'last_login_at' => now()]);

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.force_delete']))
            ->deleteJson("/api/v1/super-admin/users/{$user->uuid}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_force_delete_is_refused_for_a_user_with_any_audit_trail_even_if_never_logged_in(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $user = User::factory()->create(['role_id' => $receptionRoleId, 'last_login_at' => null]);
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'patients.create',
            'module' => 'reception',
            'site_code' => 'A',
            'site_name' => 'Ambondromamy',
            'request_uuid' => (string) Str::uuid(),
        ]);

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.force_delete']))
            ->deleteJson("/api/v1/super-admin/users/{$user->uuid}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_remote_super_admin_can_replace_a_roles_baseline_permissions(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->first();
        $keep = Permission::query()->where('name', 'reception.view')->value('id');
        $add = Permission::query()->where('name', 'trash.view')->value('id');
        $role->permissions()->sync([$keep]);
        $actorUuid = (string) Str::uuid();

        $this->withHeaders($this->headers($actorUuid, (string) Str::uuid(), ['users.manage']))
            ->putJson("/api/v1/super-admin/roles/{$role->code}/permissions", [
                'permission_ids' => [$keep, $add],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.permissions');

        $names = $role->fresh()->permissions->pluck('name')->all();
        $this->assertContains('reception.view', $names);
        $this->assertContains('trash.view', $names);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.permissions.update',
            'external_actor_uuid' => $actorUuid,
            'user_id' => null,
        ]);
    }

    public function test_role_permissions_endpoint_refuses_the_super_admin_role(): void
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->first();

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.manage']))
            ->putJson("/api/v1/super-admin/roles/{$role->code}/permissions", ['permission_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_role_permissions_endpoint_requires_users_manage_permission(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->first();

        $this->withHeaders($this->headers(idempotencyKey: (string) Str::uuid(), permissions: ['users.view']))
            ->putJson("/api/v1/super-admin/roles/{$role->code}/permissions", ['permission_ids' => []])
            ->assertForbidden();
    }

    public function test_user_model_still_refuses_an_unsanctioned_delete_call(): void
    {
        $receptionRoleId = Role::query()->where('code', 'RECEPTION')->value('id');
        $user = User::factory()->create(['role_id' => $receptionRoleId]);

        $this->expectException(\LogicException::class);

        $user->delete();
    }

    private function headers(?string $actorUuid = null, ?string $idempotencyKey = null, array $permissions = []): array
    {
        return array_filter([
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }
}
