<?php

namespace Tests\Feature\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentAccountIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function seedFor(string $deploymentType): void
    {
        config(['rivo.site.type' => $deploymentType]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    private function allowPortal(User $user): User
    {
        $permission = Permission::query()->where('name', 'super_admin.portal.view')->firstOrFail();
        $user->permissions()->syncWithoutDetaching([
            $permission->id => ['effect' => 'allow'],
        ]);

        return $user->fresh();
    }

    public function test_super_admin_cannot_authenticate_on_an_operational_site_even_with_a_portal_permission(): void
    {
        $this->seedFor('clinic');
        $user = $this->allowPortal($this->user('SUPER_ADMIN'));

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_each_operational_role_can_use_its_own_site_account(): void
    {
        $this->seedFor('clinic');

        foreach (['ADMINISTRATION', 'LOGISTICS', 'GUARD', 'RECEPTION', 'MEDICINE', 'NURSE', 'SURGERY', 'PHARMACY', 'LABORATORY'] as $roleCode) {
            $user = $this->user($roleCode);

            $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertRedirect('/');

            $this->assertAuthenticatedAs($user);
            $this->post('/logout')->assertRedirect('/');
            $this->assertGuest();
        }
    }

    public function test_operational_account_cannot_authenticate_on_admin_even_with_portal_permission(): void
    {
        $this->seedFor('admin');
        $user = $this->allowPortal($this->user('MEDICINE'));

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_super_admin_can_authenticate_only_on_the_central_portal(): void
    {
        $this->seedFor('admin');
        $user = $this->user('SUPER_ADMIN');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_super_admin_session_is_revoked_on_a_clinic_deployment(): void
    {
        $this->seedFor('clinic');
        $user = $this->allowPortal($this->user('SUPER_ADMIN'));

        $this->actingAs($user)->get('/')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_existing_operational_session_is_revoked_on_the_admin_deployment(): void
    {
        $this->seedFor('admin');
        $user = $this->allowPortal($this->user('ADMINISTRATION'));

        $this->actingAs($user)->get('/')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
