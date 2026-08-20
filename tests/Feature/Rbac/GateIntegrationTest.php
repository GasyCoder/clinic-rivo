<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GateIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_permission_is_usable_by_the_can_middleware_without_any_gate_define(): void
    {
        Route::middleware(['web', 'auth'])
            ->get('/_test/user-view', fn () => 'ok')
            ->middleware('can:users.view');

        $role = Role::query()->create(['code' => 'ADMINISTRATION', 'name' => 'Administration']);
        $permission = Permission::query()->create(['name' => 'users.view', 'label' => 'Voir les utilisateurs']);
        $role->permissions()->attach($permission);

        $allowed = User::factory()->create(['role_id' => $role->id]);
        $forbiddenRole = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);
        $forbidden = User::factory()->create(['role_id' => $forbiddenRole->id]);

        $this->actingAs($allowed)->get('/_test/user-view')->assertOk();
        $this->actingAs($forbidden)->get('/_test/user-view')->assertForbidden();
    }

    public function test_super_admin_passes_the_can_middleware_for_any_permission(): void
    {
        Route::middleware(['web', 'auth'])
            ->get('/_test/anything', fn () => 'ok')
            ->middleware('can:whatever.ability');

        $role = Role::query()->create(['code' => 'SUPER_ADMIN', 'name' => 'Super Administrateur']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get('/_test/anything')->assertOk();
    }

    public function test_an_undefined_ability_is_denied_by_default_instead_of_erroring(): void
    {
        $role = Role::query()->create(['code' => 'RECEPTION', 'name' => 'Réception']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertFalse($user->can('this.permission.does.not.exist'));
    }

    public function test_guests_are_denied_by_the_can_middleware(): void
    {
        Route::middleware(['web', 'auth'])
            ->get('/_test/guest-check', fn () => 'ok')
            ->middleware('can:users.view');

        Permission::query()->create(['name' => 'users.view']);

        $this->get('/_test/guest-check')->assertRedirect('/login');
    }
}
