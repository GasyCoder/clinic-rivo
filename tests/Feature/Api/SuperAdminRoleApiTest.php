<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le référentiel des rôles d'un site, piloté depuis le portail (ADR-100).
 *
 * L'ADR-064 avait sorti le *socle* d'un rôle du code ; le rôle lui-même
 * restait figé dans `RoleSeeder`. Ce que ces tests tiennent : un rôle se
 * crée sans déploiement, ne se supprime jamais physiquement, et ne peut pas
 * disparaître sous les pieds des comptes qui le portent.
 */
class SuperAdminRoleApiTest extends TestCase
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
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, ProfessionalProfileSeeder::class]);
    }

    /**
     * ADR-150 — le socle n'est pas la seule source des droits d'un compte : une
     * exception individuelle l'emporte (ADR-033). Un socle à zéro se lisait
     * « personne n'y a accès », et un accès bien réel passait pour un défaut.
     */
    public function test_it_says_how_many_accounts_carry_individual_exceptions(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->firstOrFail();
        [$withException, $plain] = User::factory()->count(2)->create(['role_id' => $role->id])->all();
        $permission = Permission::query()->firstOrCreate(['name' => 'hospitalization.view']);
        $withException->permissions()->attach($permission->id, ['effect' => 'allow']);

        $this->withHeaders($this->headers(permissions: ['roles.view']))
            ->getJson('/api/v1/super-admin/roles')
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'RECEPTION',
                'users_count' => 2,
                'users_with_exceptions_count' => 1,
            ]);

        $this->assertNotNull($plain->fresh());
    }

    public function test_it_lists_roles_with_their_holders_and_the_permission_catalog(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->firstOrFail();
        User::factory()->count(2)->create(['role_id' => $role->id]);

        $this->withHeaders($this->headers(permissions: ['roles.view']))
            ->getJson('/api/v1/super-admin/roles')
            ->assertOk()
            ->assertJsonPath('meta.site.code', 'A')
            ->assertJsonFragment(['code' => 'RECEPTION', 'users_count' => 2])
            // Le socle SUPER_ADMIN n'est jamais réglé ici : il se déduit du
            // type de déploiement (ADR-025, ADR-027).
            ->assertJsonFragment(['code' => 'SUPER_ADMIN', 'protected' => true]);
    }

    public function test_a_super_admin_creates_a_role_with_the_permissions_actually_sent(): void
    {
        $actorUuid = (string) Str::uuid();
        $ids = Permission::query()->whereIn('name', ['care.view', 'vitals.view'])->pluck('id')->all();

        $this->withHeaders($this->headers($actorUuid, permissions: ['roles.create']))
            ->postJson('/api/v1/super-admin/roles', [
                'code' => 'kinesitherapeute',
                'name' => 'Kinésithérapeute',
                'permission_ids' => $ids,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'KINESITHERAPEUTE')
            ->assertJsonPath('data.users_count', 0);

        $role = Role::query()->where('code', 'KINESITHERAPEUTE')->sole();
        $this->assertEqualsCanonicalizing(['care.view', 'vitals.view'], $role->permissions->pluck('name')->all());

        // L'acteur distant n'a pas de ligne locale : l'audit doit malgré
        // tout dire qui a créé ce rôle.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.create',
            'external_actor_uuid' => $actorUuid,
            'user_id' => null,
        ]);
    }

    /** Le code est l'identité du rôle : les socles et l'audit le désignent. */
    public function test_a_malformed_code_is_refused_before_anything_is_written(): void
    {
        $this->withHeaders($this->headers(permissions: ['roles.create']))
            ->postJson('/api/v1/super-admin/roles', [
                'code' => 'kiné thérapeute',
                'name' => 'Kinésithérapeute',
                'permission_ids' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame(0, Role::query()->where('name', 'Kinésithérapeute')->count());
    }

    /** Un code repris par un rôle archivé se restaure, il ne se recrée pas. */
    public function test_the_code_of_an_archived_role_is_not_reusable(): void
    {
        $this->withHeaders($this->headers(permissions: ['roles.create']))
            ->postJson('/api/v1/super-admin/roles', ['code' => 'PHYSIO', 'name' => 'Physio', 'permission_ids' => []])
            ->assertCreated();

        $this->withHeaders($this->headers(permissions: ['roles.archive']))
            ->deleteJson('/api/v1/super-admin/roles/PHYSIO', ['reason' => 'Métier repris ailleurs.'])
            ->assertOk();

        $this->withHeaders($this->headers(permissions: ['roles.create']))
            ->postJson('/api/v1/super-admin/roles', ['code' => 'PHYSIO', 'name' => 'Physio bis', 'permission_ids' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /**
     * Le refus central : un rôle encore porté ne s'archive pas. La relation
     * `User::role()` ne renvoie plus un rôle archivé — ses titulaires
     * perdraient tout leur socle d'un coup, sans que rien ne le dise.
     */
    public function test_a_role_still_held_by_an_account_cannot_be_archived(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->firstOrFail();
        User::factory()->create(['role_id' => $role->id]);

        $this->withHeaders($this->headers(permissions: ['roles.archive']))
            ->deleteJson('/api/v1/super-admin/roles/RECEPTION', ['reason' => 'Réorganisation du service.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertNull(Role::query()->withTrashed()->where('code', 'RECEPTION')->sole()->deleted_at);
    }

    /** Archiver n'est pas supprimer : le socle revient tel quel (ADR-009/010). */
    public function test_archiving_keeps_the_role_and_its_baseline_restorable(): void
    {
        $this->withHeaders($this->headers(permissions: ['roles.create']))
            ->postJson('/api/v1/super-admin/roles', [
                'code' => 'PHYSIO',
                'name' => 'Physio',
                'permission_ids' => Permission::query()->where('name', 'care.view')->pluck('id')->all(),
            ])->assertCreated();

        $this->withHeaders($this->headers(permissions: ['roles.archive']))
            ->deleteJson('/api/v1/super-admin/roles/PHYSIO', ['reason' => 'Métier suspendu cette année.'])
            ->assertOk();

        $archived = Role::query()->withTrashed()->where('code', 'PHYSIO')->sole();
        $this->assertNotNull($archived->deleted_at);
        $this->assertSame('Métier suspendu cette année.', $archived->delete_reason);
        $this->assertSame(['care.view'], $archived->permissions->pluck('name')->all());

        $this->withHeaders($this->headers(permissions: ['roles.restore']))
            ->postJson('/api/v1/super-admin/roles/PHYSIO/restore')
            ->assertOk()
            ->assertJsonPath('data.archived', false);

        $restored = Role::query()->where('code', 'PHYSIO')->sole();
        $this->assertNull($restored->delete_reason);
        $this->assertSame(['care.view'], $restored->permissions->pluck('name')->all());
    }

    /** Le libellé se corrige ; le code, jamais. */
    public function test_renaming_changes_the_label_and_never_the_code(): void
    {
        $this->withHeaders($this->headers(permissions: ['roles.update']))
            ->putJson('/api/v1/super-admin/roles/RECEPTION', ['name' => 'Accueil & Caisse', 'code' => 'ACCUEIL'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Accueil & Caisse')
            ->assertJsonPath('data.code', 'RECEPTION');
    }

    public function test_the_super_admin_role_is_never_renamed_or_archived_from_a_site(): void
    {
        $this->withHeaders($this->headers(permissions: ['roles.update']))
            ->putJson('/api/v1/super-admin/roles/SUPER_ADMIN', ['name' => 'Direction'])
            ->assertStatus(422);

        $this->withHeaders($this->headers(permissions: ['roles.archive']))
            ->deleteJson('/api/v1/super-admin/roles/SUPER_ADMIN', ['reason' => 'Inutilisé sur ce site.'])
            ->assertStatus(422);
    }

    /** L'autorisation est revérifiée par le site, jamais accordée par le portail. */
    public function test_the_site_refuses_a_remote_actor_without_the_permission(): void
    {
        $this->withHeaders($this->headers(permissions: ['roles.view']))
            ->postJson('/api/v1/super-admin/roles', ['code' => 'PHYSIO', 'name' => 'Physio', 'permission_ids' => []])
            ->assertForbidden();
    }

    /**
     * Les exceptions individuelles ont leur propre endpoint : l'écran qui
     * les règle ne modifie ni l'identité du compte, ni son rôle, et ne doit
     * donc pas avoir à les réexpédier.
     */
    public function test_individual_exceptions_are_updated_without_touching_the_account(): void
    {
        $role = Role::query()->where('code', 'RECEPTION')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id, 'name' => 'Florent', 'email' => 'florent@example.test']);
        $permissionId = Permission::query()->where('name', 'billing.print')->value('id');

        $this->withHeaders($this->headers(permissions: ['permissions.assign']))
            ->putJson("/api/v1/super-admin/roles/accounts/{$user->uuid}/permissions", [
                'permission_overrides' => [['permission_id' => $permissionId, 'effect' => 'deny']],
            ])
            ->assertOk();

        $user->refresh()->load('permissions');
        $this->assertSame('deny', $user->permissions->firstWhere('id', $permissionId)->pivot->effect);
        $this->assertSame('Florent', $user->name);
        $this->assertSame($role->id, $user->role_id);

        // Une liste vide retire les exceptions, sans toucher au socle.
        $this->withHeaders($this->headers(permissions: ['permissions.assign']))
            ->putJson("/api/v1/super-admin/roles/accounts/{$user->uuid}/permissions", ['permission_overrides' => []])
            ->assertOk();

        $this->assertCount(0, $user->refresh()->load('permissions')->permissions);
        $this->assertTrue($user->can('reception.view'));
    }

    /**
     * Un rôle archivé n'est plus affectable : `User::role()` ne le renvoie
     * plus, si bien qu'un compte créé dessus se retrouverait sans aucun
     * socle — sans que rien ne le dise.
     */
    public function test_an_archived_role_can_no_longer_be_assigned_to_an_account(): void
    {
        $this->withHeaders($this->headers(permissions: ['roles.create']))
            ->postJson('/api/v1/super-admin/roles', ['code' => 'PHYSIO', 'name' => 'Physio', 'permission_ids' => []])
            ->assertCreated();

        $roleId = Role::query()->where('code', 'PHYSIO')->value('id');

        $this->withHeaders($this->headers(permissions: ['roles.archive']))
            ->deleteJson('/api/v1/super-admin/roles/PHYSIO', ['reason' => 'Métier suspendu cette année.'])
            ->assertOk();

        $this->withHeaders($this->headers(permissions: ['users.create', 'roles.assign']))
            ->postJson('/api/v1/super-admin/users', [
                'name' => 'Nouvel agent',
                'email' => 'agent@example.test',
                'role_id' => $roleId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_id');
    }

    /** @param array<int, string> $permissions */
    private function headers(?string $actorUuid = null, ?string $idempotencyKey = null, array $permissions = []): array
    {
        return array_filter([
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }
}
