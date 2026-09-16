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

/**
 * « Rôles & permissions » au portail, séparé de « Utilisateurs » (ADR-100).
 *
 * Ce que ces tests tiennent : le portail ne touche jamais une base clinique
 * (ADR-004, ADR-027) — chaque commande part vers l'API du site choisi, et
 * c'est le site qui décide.
 */
class RolesPortalTest extends TestCase
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
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_the_workspace_reads_roles_accounts_and_catalog_from_each_site_api(): void
    {
        $this->fakeRoles();

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/workspaces/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Roles/Index')
                ->has('sites', 1)
                ->where('sites.0.data.roles.0.code', 'RECEPTION')
                ->where('sites.0.data.roles.0.users_count', 2)
                ->where('sites.0.data.users.0.name', 'Florent')
                ->has('sites.0.data.permission_catalog', 1));

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://m.test/api/v1/super-admin/roles'));
    }

    public function test_creating_a_role_is_forwarded_to_the_chosen_site(): void
    {
        $this->fakeRoles([
            'https://m.test/api/v1/super-admin/roles' => Http::response([
                'message' => 'Rôle Kinésithérapeute créé.',
                'data' => ['code' => 'KINESITHERAPEUTE'],
            ], 201),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/workspaces/roles', [
                'site_code' => 'M',
                'code' => 'KINESITHERAPEUTE',
                'name' => 'Kinésithérapeute',
                'permission_ids' => [1],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/roles'
            && $request->hasHeader('Idempotency-Key')
            && $request['code'] === 'KINESITHERAPEUTE'
            && $request['permission_ids'] === [1]);
    }

    /** Le refus du site remonte tel quel : le portail n'invente pas un succès. */
    public function test_a_refusal_from_the_site_becomes_a_form_error(): void
    {
        $this->fakeRoles([
            'https://m.test/api/v1/super-admin/roles/RECEPTION' => Http::response([
                'message' => 'Ce rôle est encore porté par 2 compte(s).',
                'errors' => ['reason' => ['Ce rôle est encore porté par 2 compte(s).']],
            ], 422),
        ]);

        $this->actingAs($this->superAdmin)
            ->delete('/super-admin/workspaces/roles/M/RECEPTION', ['reason' => 'Réorganisation du service.'])
            ->assertRedirect()
            ->assertSessionHasErrors('reason');
    }

    /**
     * Les exceptions d'un compte ont leur propre commande : cet écran ne
     * modifie ni l'identité ni le rôle, il ne les réexpédie donc pas.
     */
    public function test_individual_exceptions_are_sent_alone(): void
    {
        $this->fakeRoles([
            'https://m.test/api/v1/super-admin/roles/accounts/*' => Http::response([
                'message' => 'Permissions individuelles de Florent mises à jour.',
                'data' => [],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/workspaces/roles/M/accounts/11111111-1111-4111-8111-111111111111/permissions', [
                'permission_overrides' => [['permission_id' => 1, 'effect' => 'deny']],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            return $request->url() === 'https://m.test/api/v1/super-admin/roles/accounts/11111111-1111-4111-8111-111111111111/permissions'
                && $request['permission_overrides'] === [['permission_id' => 1, 'effect' => 'deny']]
                // Ni nom, ni e-mail, ni rôle : rien de ce que cet écran ne règle pas.
                && ! array_key_exists('name', $request->data())
                && ! array_key_exists('role_id', $request->data());
        });
    }

    /**
     * Le catalogue des permissions se pilote depuis le même écran, et par la
     * même route : l'API du site décide, le portail ne fait que transmettre.
     */
    public function test_creating_a_permission_is_forwarded_to_the_chosen_site(): void
    {
        $this->fakeRoles([
            'https://m.test/api/v1/super-admin/permissions' => Http::response([
                'message' => 'Permission « kinesitherapie.view » créée.',
                'data' => ['name' => 'kinesitherapie.view', 'used_by_app' => false],
            ], 201),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/workspaces/roles/permissions', [
                'site_code' => 'M',
                'name' => 'kinesitherapie.view',
                'label' => 'Voir les séances de kinésithérapie',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/permissions'
            && $request['name'] === 'kinesitherapie.view');
    }

    /** @param array<string, mixed> $extra */
    private function fakeRoles(array $extra = []): void
    {
        Http::fake([
            ...$extra,
            'https://m.test/api/v1/super-admin/roles*' => Http::response([
                'data' => [
                    'roles' => [[
                        'id' => 1, 'code' => 'RECEPTION', 'name' => 'Réception', 'protected' => false,
                        'archived' => false, 'archived_at' => null, 'archive_reason' => null,
                        'users_count' => 2, 'permissions' => ['cash.view'], 'profiles' => [],
                    ]],
                    'users' => [[
                        'uuid' => '11111111-1111-4111-8111-111111111111', 'name' => 'Florent', 'email' => 'florent@m.test',
                        'role' => ['id' => 1, 'code' => 'RECEPTION', 'name' => 'Réception'],
                        'professional_profile' => null, 'active' => true, 'last_login_at' => null,
                        'deactivated_at' => null, 'deactivation_reason' => null, 'deletable' => false,
                        'permission_overrides' => [],
                    ]],
                    'permission_catalog' => [[
                        'id' => 1, 'name' => 'cash.view', 'label' => 'Voir la caisse', 'module' => 'cash',
                        'used_by_app' => true, 'roles_count' => 1, 'accounts_count' => 0,
                    ]],
                ],
                'meta' => ['site' => ['code' => 'M', 'name' => 'Mampikony']],
            ], 200),
        ]);
    }
}
