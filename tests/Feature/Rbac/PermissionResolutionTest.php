<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $code): Role
    {
        return Role::query()->create(['code' => $code, 'name' => $code]);
    }

    private function permission(string $name): Permission
    {
        return Permission::query()->create(['name' => $name, 'label' => $name]);
    }

    public function test_user_without_role_has_no_permissions(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $this->assertFalse($user->hasPermissionTo('users.view'));
    }

    public function test_user_inherits_permission_granted_to_their_role(): void
    {
        $role = $this->role('ADMINISTRATION');
        $permission = $this->permission('users.view');
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertTrue($user->hasPermissionTo('users.view'));
    }

    public function test_user_does_not_have_a_permission_their_role_lacks(): void
    {
        $role = $this->role('RECEPTION');
        $this->permission('users.view');

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertFalse($user->hasPermissionTo('users.view'));
    }

    public function test_explicit_user_allow_grants_a_permission_the_role_does_not_have(): void
    {
        $role = $this->role('RECEPTION');
        $permission = $this->permission('users.view');

        $user = User::factory()->create(['role_id' => $role->id]);
        $user->permissions()->attach($permission->id, ['effect' => 'allow']);

        $fresh = User::find($user->id);

        $this->assertTrue($fresh->hasPermissionTo('users.view'));
    }

    public function test_explicit_user_deny_overrides_a_permission_granted_by_the_role(): void
    {
        $role = $this->role('ADMINISTRATION');
        $permission = $this->permission('users.view');
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['role_id' => $role->id]);
        $user->permissions()->attach($permission->id, ['effect' => 'deny']);

        $fresh = User::find($user->id);

        $this->assertFalse($fresh->hasPermissionTo('users.view'));
    }

    public function test_super_admin_holds_every_known_permission(): void
    {
        $role = $this->role('SUPER_ADMIN');
        $this->permission('users.view');
        $this->permission('users.manage');

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->assertTrue($user->hasPermissionTo('users.view'));
        $this->assertTrue($user->hasPermissionTo('users.manage'));
    }

    public function test_a_permission_created_after_the_user_still_applies_without_code_changes(): void
    {
        $role = $this->role('SUPER_ADMIN');
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->permission('laboratory.validate');

        $fresh = User::find($user->id);

        $this->assertTrue($fresh->hasPermissionTo('laboratory.validate'));
    }

    public function test_explicit_deny_remains_prioritary_for_super_admin(): void
    {
        $role = $this->role('SUPER_ADMIN');
        $permission = $this->permission('settings.update');
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->permissions()->attach($permission->id, ['effect' => 'deny']);

        $fresh = User::find($user->id);

        $this->assertFalse($fresh->hasPermissionTo('settings.update'));
        $this->assertFalse($fresh->can('settings.update'));
    }
}
