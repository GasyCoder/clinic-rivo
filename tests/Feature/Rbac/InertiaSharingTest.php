<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InertiaSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_permissions_and_role_are_shared_with_the_frontend(): void
    {
        $role = Role::query()->create(['code' => 'ADMINISTRATION', 'name' => 'Administration']);
        $permission = Permission::query()->create(['name' => 'user.view', 'label' => 'Voir les utilisateurs']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['role_id' => $role->id]);

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user.role.code', 'ADMINISTRATION')
            ->where('permissions', ['user.view'])
        );
    }

    public function test_guest_pages_share_an_empty_permission_list(): void
    {
        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page
            ->where('auth.user', null)
            ->where('permissions', [])
        );
    }
}
