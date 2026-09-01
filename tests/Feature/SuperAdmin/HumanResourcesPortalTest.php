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

class HumanResourcesPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
                ['code' => 'B', 'name' => 'Boriziny', 'api_url' => 'https://b.test/api/v1', 'api_token' => 'b-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_portal_consolidates_available_site_hr_apis_and_isolates_failures(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/human-resources' => Http::response($this->payload(8, 6, 1), 200),
            'https://a.test/api/v1/super-admin/human-resources' => Http::response($this->payload(5, 4, 2), 200),
            'https://b.test/api/v1/super-admin/human-resources' => Http::response(['message' => 'Indisponible'], 503),
        ]);
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);

        $this->actingAs($actor)->get('/super-admin/workspaces/hr')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/HumanResources/Index')
                ->has('sites', 3)
                ->where('summary.online_sites', 2)
                ->where('summary.active_employees', 13)
                ->where('summary.current_contracts', 10)
                ->where('summary.pending_leave', 3));

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://m.test/api/v1/super-admin/human-resources'
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'employees.view'));
    }

    /** @return array<string, mixed> */
    private function payload(int $employees, int $contracts, int $pendingLeave): array
    {
        return ['data' => [
            'summary' => [
                'active_employees' => $employees,
                'inactive_employees' => 0,
                'archived_employees' => 0,
                'current_contracts' => $contracts,
                'contracts_ending_soon' => 1,
                'open_attendance' => 1,
                'pending_leave' => $pendingLeave,
                'upcoming_shifts' => 3,
            ],
            'departments' => [],
            'permissions' => ['contracts' => true, 'attendance' => true, 'leave' => true, 'planning' => true],
        ]];
    }
}
