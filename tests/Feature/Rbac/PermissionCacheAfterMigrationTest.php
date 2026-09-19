<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Une permission ajoutée par migration (DB::table, sans événement de modèle)
 * doit être reconnue dès la fin des migrations : sinon le Gate l'ignore et
 * répond 403 à un rôle qui la détient (constaté sur hospitalization.view).
 */
class PermissionCacheAfterMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_permission_inserted_by_a_migration_is_recognised_once_migrations_end(): void
    {
        $role = Role::query()->firstOrCreate(['code' => 'MEDICINE'], ['name' => 'Médecine']);
        $user = User::factory()->create(['role_id' => $role->id]);

        // Warm the cache before the "migration" runs.
        $this->assertFalse(Permission::allNames()->contains('ward.view'));

        $id = DB::table('permissions')->insertGetId(['name' => 'ward.view', 'label' => 'Voir', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        // Stale cache: the Gate does not know the name yet.
        $this->assertFalse($user->fresh()->can('ward.view'));

        event(new MigrationsEnded('up'));

        $this->assertTrue($user->fresh()->can('ward.view'));
    }
}
