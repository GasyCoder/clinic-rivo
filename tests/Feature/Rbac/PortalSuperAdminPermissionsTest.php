<?php

namespace Tests\Feature\Rbac;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-186 — le Super Admin du portail détient réellement toutes les
 * permissions. Constat : 18 permissions n'existaient pas dans la base du
 * portail et 26 n'étaient pas accordées au rôle ; « Canevas de documents »
 * avait disparu de son menu, et le site refusait les commandes
 * correspondantes, le portail lui transmettant ses droits.
 */
class PortalSuperAdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function portalDriftedFromTheCatalog(): Role
    {
        config(['rivo.site.type' => 'admin', 'rivo.site.code' => 'ADMIN']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);

        // Ce qu'une base en service a réellement vécu : des permissions
        // arrivées après le seeder, jamais accordées au portail…
        $superAdmin = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $superAdmin->permissions()->detach(Permission::query()->whereIn('name', ['hospitalization.view', 'newborns.view'])->pluck('id'));

        // … et d'autres que seul PermissionSeeder aurait créées.
        DB::table('permissions')->whereIn('name', ['document_templates.view', 'generated_documents.view'])->delete();

        return $superAdmin;
    }

    public function test_a_migration_run_on_the_portal_grants_every_permission_to_the_super_admin(): void
    {
        $superAdmin = $this->portalDriftedFromTheCatalog();
        $user = User::factory()->create(['role_id' => $superAdmin->id]);

        $this->assertFalse($user->fresh()->can('document_templates.view'));
        $this->assertFalse($user->fresh()->can('hospitalization.view'));

        event(new NoPendingMigrations('up'));

        $held = $superAdmin->fresh()->permissions()->pluck('name');

        $this->assertSame(Permission::query()->count(), $held->count(), 'le Super Admin détient tout le catalogue du portail');
        $this->assertEmpty(array_diff(array_keys(PermissionSeeder::PERMISSIONS), $held->all()));
        $this->assertTrue($user->fresh()->can('document_templates.view'));
        $this->assertTrue($user->fresh()->can('generated_documents.view'));
        $this->assertTrue($user->fresh()->can('hospitalization.view'));

        $audit = AuditLog::query()->where('action', 'role.permissions.portal_sync')->sole();
        $this->assertEqualsCanonicalizing(['document_templates.view', 'generated_documents.view'], $audit->new_values['created']);
        $this->assertContains('hospitalization.view', $audit->new_values['granted']);
    }

    public function test_the_sync_only_adds_and_keeps_every_other_decision(): void
    {
        $superAdmin = $this->portalDriftedFromTheCatalog();
        $reception = Role::query()->where('code', 'RECEPTION')->firstOrFail();
        $receptionBefore = $reception->permissions()->pluck('name')->sort()->values()->all();

        $user = User::factory()->create(['role_id' => $superAdmin->id]);
        $denied = Permission::query()->where('name', 'audit.view')->firstOrFail();
        $user->permissions()->attach($denied->id, ['effect' => 'deny', 'source' => 'MANUAL']);

        event(new MigrationsEnded('up'));

        $this->assertSame($receptionBefore, $reception->fresh()->permissions()->pluck('name')->sort()->values()->all(), 'aucun autre rôle n’est touché');
        $this->assertFalse($user->fresh()->can('audit.view'), 'un refus individuel garde la priorité (ADR-033)');

        // Rejouée, elle n'a plus rien à faire : aucune seconde trace.
        event(new NoPendingMigrations('up'));
        $this->assertSame(1, AuditLog::query()->where('action', 'role.permissions.portal_sync')->count());
    }

    public function test_a_clinic_site_never_grants_anything_to_the_super_admin(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $superAdmin = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();

        event(new NoPendingMigrations('up'));

        $this->assertSame(0, $superAdmin->fresh()->permissions()->count(), 'ADR-027 : aucune permission sur un site');
        $this->assertSame(0, AuditLog::query()->where('action', 'role.permissions.portal_sync')->count());
    }
}
