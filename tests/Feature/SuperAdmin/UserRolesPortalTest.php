<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserRolesPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_roles_workspace_aggregates_users_roles_and_permission_catalog_per_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/users*' => Http::response([
                'data' => [
                    'users' => [[
                        'uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Florent', 'email' => 'florent@m.test',
                        'role' => ['id' => 1, 'code' => 'RECEPTION', 'name' => 'Réception'],
                        'professional_profile' => null, 'active' => true, 'last_login_at' => null,
                        'deactivated_at' => null, 'deactivation_reason' => null, 'permission_overrides' => [],
                    ]],
                    'roles' => [['id' => 1, 'code' => 'RECEPTION', 'name' => 'Réception', 'permissions' => ['cash.view'], 'profiles' => []]],
                    'permission_catalog' => [['id' => 1, 'name' => 'cash.view', 'label' => 'Voir la caisse', 'module' => 'cash']],
                ],
                'meta' => ['site' => ['code' => 'M', 'name' => 'Mampikony']],
            ], 200),
            'https://a.test/api/v1/super-admin/users*' => Http::response([
                'data' => ['users' => [], 'roles' => [], 'permission_catalog' => []],
                'meta' => ['site' => ['code' => 'A', 'name' => 'Ambondromamy']],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/roles?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Users/Index')
                ->has('sites', 2)
                ->where('sites.0.data.users.0.name', 'Florent')
                ->where('sites.0.data.roles.0.code', 'RECEPTION')
                ->where('filters.status', 'active'));

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://m.test/api/v1/super-admin/users')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'users.view'));
    }

    public function test_create_and_deactivate_are_sent_only_to_the_selected_site_with_super_admin_identity(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/users' => Http::response([
                'message' => 'Compte créé.', 'data' => ['uuid' => 'x', 'name' => 'Andry'],
            ], 201),
            'https://m.test/api/v1/super-admin/users/22222222-2222-4222-8222-222222222222/deactivate' => Http::response([
                'message' => 'Compte désactivé.', 'data' => ['uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Andry'],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/roles', [
            'site_code' => 'M',
            'name' => 'Andry',
            'email' => 'andry@m.test',
            'password' => 'Correct-Horse-Battery-9!',
            'password_confirmation' => 'Correct-Horse-Battery-9!',
            'role_id' => 3,
        ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/users'
            && $request->hasHeader('Idempotency-Key')
            && $request['name'] === 'Andry');

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/workspaces/roles/M/22222222-2222-4222-8222-222222222222/deactivate', [
                'reason' => 'Fin de mission',
            ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/deactivate')
            && $request['reason'] === 'Fin de mission');
    }

    public function test_profile_sync_choice_and_manual_overrides_are_forwarded_to_the_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/users/22222222-2222-4222-8222-222222222222' => Http::response([
                'message' => 'Compte mis à jour.',
                'data' => ['uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'Soa'],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/workspaces/roles/M/22222222-2222-4222-8222-222222222222', [
                'name' => 'Soa',
                'email' => 'soa@m.test',
                'role_id' => 8,
                'professional_profile_id' => 12,
                'sync_profile_permissions' => true,
                'permission_overrides' => [
                    ['permission_id' => 44, 'effect' => 'deny'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://m.test/api/v1/super-admin/users/22222222-2222-4222-8222-222222222222'
            && $request['professional_profile_id'] === 12
            && $request['sync_profile_permissions'] === true
            && $request['permission_overrides'] === [['permission_id' => 44, 'effect' => 'deny']]);
    }

    public function test_force_delete_is_sent_only_to_the_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/users/33333333-3333-4333-8333-333333333333' => Http::response([
                'message' => 'Compte de Test supprimé définitivement.',
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->delete('/super-admin/workspaces/roles/M/33333333-3333-4333-8333-333333333333')
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://m.test/api/v1/super-admin/users/33333333-3333-4333-8333-333333333333');
    }

    public function test_bulk_force_delete_is_sent_only_to_the_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/users/bulk/force-delete' => Http::response([
                'message' => '2 compte(s) supprimé(s) définitivement.', 'data' => ['processed' => 2],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)->post('/super-admin/workspaces/roles/bulk/force-delete', [
            'site_code' => 'M',
            'uuids' => ['33333333-3333-4333-8333-333333333333', '44444444-4444-4444-8444-444444444444'],
        ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/users/bulk/force-delete'
            && $request->hasHeader('Idempotency-Key')
            && count($request['uuids']) === 2);
    }

    public function test_role_baseline_permissions_update_is_sent_only_to_the_selected_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/roles/RECEPTION/permissions' => Http::response([
                'message' => 'Permissions du rôle Réception mises à jour.',
                'data' => ['id' => 3, 'code' => 'RECEPTION', 'permissions' => ['reception.view', 'trash.view']],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/workspaces/roles/M/permissions/RECEPTION', [
                'permission_ids' => [1, 2],
            ])->assertRedirect()->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://m.test/api/v1/super-admin/roles/RECEPTION/permissions'
            && $request->hasHeader('Idempotency-Key')
            && $request['permission_ids'] === [1, 2]);
    }
}
