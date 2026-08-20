<?php

namespace Tests\Feature\Rbac;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    private function user(string $roleCode): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
        ]);
    }

    public function test_each_role_is_confined_to_its_authorized_module_routes(): void
    {
        $boundaries = [
            'RECEPTION' => [
                'allowed' => ['/reception', '/cash', '/patients'],
                'denied' => ['/surgery', '/administration/users', '/administration/catalog'],
            ],
            'MEDICINE' => [
                'allowed' => ['/patients'],
                'denied' => ['/reception', '/cash', '/surgery', '/administration/users', '/administration/catalog'],
            ],
            'NURSE' => [
                'allowed' => ['/patients'],
                'denied' => ['/reception', '/cash', '/surgery', '/administration/users', '/administration/catalog'],
            ],
            'SURGERY' => [
                'allowed' => ['/surgery'],
                'denied' => ['/reception', '/cash', '/patients', '/administration/users', '/administration/catalog'],
            ],
            'ADMINISTRATION' => [
                'allowed' => ['/administration'],
                'denied' => ['/logistics', '/reception/visitors', '/pharmacy', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
            'LOGISTICS' => [
                'allowed' => ['/logistics'],
                'denied' => ['/administration', '/reception/visitors', '/pharmacy', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
            'GUARD' => [
                'allowed' => ['/reception/visitors'],
                'denied' => ['/administration', '/logistics', '/pharmacy', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
            'PHARMACY' => [
                'allowed' => ['/pharmacy'],
                'denied' => ['/administration', '/logistics', '/reception/visitors', '/administration/users', '/reception', '/cash', '/patients', '/surgery', '/administration/catalog'],
            ],
        ];

        foreach ($boundaries as $roleCode => $routes) {
            $user = $this->user($roleCode);

            foreach ($routes['allowed'] as $route) {
                $this->actingAs($user)->get($route)->assertOk();
            }

            foreach ($routes['denied'] as $route) {
                $this->actingAs($user)->get($route)->assertForbidden();
            }
        }
    }
}
