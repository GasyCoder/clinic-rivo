<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Authorization\PermissionUsageScanner;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le catalogue des permissions d'un site (ADR-101).
 *
 * Ce que ces tests tiennent : on peut créer une permission, l'écran dit
 * honnêtement si l'application la vérifie, et on ne peut pas retirer un mot
 * dont dépend un contrôle existant.
 */
class SuperAdminPermissionApiTest extends TestCase
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
        app(PermissionUsageScanner::class)->forget();
    }

    /**
     * La distinction qui porte tout l'écran : une permission que le code
     * vérifie n'est pas la même chose qu'un mot posé au catalogue.
     */
    public function test_the_catalog_says_which_permissions_the_application_actually_checks(): void
    {
        $response = $this->withHeaders($this->headers(permissions: ['permissions.view']))
            ->getJson('/api/v1/super-admin/permissions')
            ->assertOk();

        $rows = collect($response->json('data.permissions'))->keyBy('name');

        // Protège une vraie route (`can:patients.view`).
        $this->assertTrue($rows['patients.view']['used_by_app']);
        // Retirée par l'ADR-060 : plus aucun code ne la cite.
        $this->assertFalse($rows['cash_registers.release']['used_by_app']);

        $this->assertGreaterThan(0, $rows['patients.view']['roles_count']);
    }

    public function test_a_super_admin_creates_a_permission_and_it_is_announced_as_not_yet_checked(): void
    {
        $actorUuid = (string) Str::uuid();

        $this->withHeaders($this->headers($actorUuid, permissions: ['permissions.create']))
            ->postJson('/api/v1/super-admin/permissions', [
                'name' => 'Kinesitherapie.View',
                'label' => 'Voir les séances de kinésithérapie',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'kinesitherapie.view')
            ->assertJsonPath('data.used_by_app', false);

        $this->assertDatabaseHas('permissions', ['name' => 'kinesitherapie.view']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'permission.create',
            'external_actor_uuid' => $actorUuid,
            'user_id' => null,
        ]);
    }

    /** Le nom est ce que le code écrit en clair : sa forme est contrainte. */
    public function test_a_malformed_name_is_refused(): void
    {
        foreach (['kinésithérapie.view', 'kinesitherapie', 'Kine Therapie.view'] as $name) {
            $this->withHeaders($this->headers(permissions: ['permissions.create']))
                ->postJson('/api/v1/super-admin/permissions', ['name' => $name, 'label' => 'Essai'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->assertSame(0, Permission::query()->where('label', 'Essai')->count());
    }

    public function test_an_existing_name_is_refused(): void
    {
        $this->withHeaders($this->headers(permissions: ['permissions.create']))
            ->postJson('/api/v1/super-admin/permissions', ['name' => 'patients.view', 'label' => 'Doublon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /** Le libellé se reformule ; le nom, jamais. */
    public function test_only_the_label_can_be_corrected(): void
    {
        $permission = Permission::query()->where('name', 'patients.view')->sole();

        $this->withHeaders($this->headers(permissions: ['permissions.update']))
            ->putJson("/api/v1/super-admin/permissions/{$permission->id}", [
                'label' => 'Consulter les dossiers patients',
                'name' => 'autre.chose',
            ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Consulter les dossiers patients')
            ->assertJsonPath('data.name', 'patients.view');
    }

    /**
     * Le refus qui compte : supprimer une permission que `can:` vérifie ne
     * retire pas le contrôle, il le rend impossible à satisfaire — l'écran
     * devient inaccessible à tout le monde, sans message.
     */
    public function test_a_permission_the_application_checks_is_never_removed(): void
    {
        $permission = Permission::query()->where('name', 'patients.view')->sole();
        $permission->roles()->detach();
        $permission->users()->detach();

        $this->withHeaders($this->headers(permissions: ['permissions.delete']))
            ->deleteJson("/api/v1/super-admin/permissions/{$permission->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('permission');

        $this->assertDatabaseHas('permissions', ['name' => 'patients.view']);
    }

    /** Une permission encore accordée non plus : le socle la désigne. */
    public function test_a_permission_still_granted_is_never_removed(): void
    {
        $permission = Permission::query()->create(['name' => 'kinesitherapie.view', 'label' => 'Kiné']);
        Role::query()->where('code', 'NURSE')->sole()->permissions()->attach($permission->id);

        $this->withHeaders($this->headers(permissions: ['permissions.delete']))
            ->deleteJson("/api/v1/super-admin/permissions/{$permission->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('permission');
    }

    /** Jamais vérifiée, jamais accordée : son retrait ne change l'accès de personne. */
    public function test_an_orphan_permission_can_be_removed(): void
    {
        $permission = Permission::query()->create(['name' => 'kinesitherapie.view', 'label' => 'Kiné']);

        $this->withHeaders($this->headers(permissions: ['permissions.delete']))
            ->deleteJson("/api/v1/super-admin/permissions/{$permission->id}")
            ->assertOk();

        $this->assertDatabaseMissing('permissions', ['name' => 'kinesitherapie.view']);
    }

    public function test_the_site_refuses_a_remote_actor_without_the_permission(): void
    {
        $this->withHeaders($this->headers(permissions: ['permissions.view']))
            ->postJson('/api/v1/super-admin/permissions', ['name' => 'kinesitherapie.view', 'label' => 'Kiné'])
            ->assertForbidden();
    }

    /**
     * Une permission peut entrer en base sans libellé — un seeder partiel,
     * un ajout manuel. Envoyé tel quel, ce `null` faisait tomber l'écran du
     * portail : trier dessus interrompt le rendu, et Vue ne s'en relève pas.
     * Le nom est le repli — c'est ce que le code écrit, et c'est lisible.
     */
    public function test_a_permission_without_a_label_is_served_under_its_name(): void
    {
        Permission::query()->create(['name' => 'kinesitherapie.view', 'label' => null]);

        $response = $this->withHeaders($this->headers(permissions: ['permissions.view']))
            ->getJson('/api/v1/super-admin/permissions')
            ->assertOk();

        $row = collect($response->json('data.permissions'))->firstWhere('name', 'kinesitherapie.view');

        $this->assertSame('kinesitherapie.view', $row['label']);
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
