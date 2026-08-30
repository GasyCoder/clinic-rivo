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

class TrashPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.clinics' => [
                ['code' => 'M', 'name' => 'Mampikony', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_trash_page_aggregates_sites_and_exposes_filters_and_categories(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/trash*' => Http::response($this->payload('M', 'Mampikony'), 200),
            'https://a.test/api/v1/super-admin/trash*' => Http::response($this->payload('A', 'Ambondromamy'), 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->get('/super-admin/trash?category=PATIENT&site=M&search=Rakoto')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/Trash/Index')
                ->has('sites', 2)
                ->has('categories', 5)
                ->where('filters.category', 'PATIENT')
                ->where('filters.site', 'M')
                ->where('filters.search', 'Rakoto')
                ->where('sites.0.data.0.title', 'RAKOTO Soa'));

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://m.test/api/v1/super-admin/trash')
            && str_contains($request->url(), 'category=PATIENT')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'trash.view'));
    }

    public function test_restore_is_sent_only_to_the_selected_site_with_super_admin_permissions(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/trash/PATIENT/11111111-1111-4111-8111-111111111111/restore' => Http::response([
                'message' => 'Élément restauré et opération auditée.',
                'data' => ['category' => 'PATIENT', 'uuid' => '11111111-1111-4111-8111-111111111111'],
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/trash/M/PATIENT/11111111-1111-4111-8111-111111111111/restore')
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://m.test/api/v1/super-admin/trash/PATIENT/11111111-1111-4111-8111-111111111111/restore'
            && $request->hasHeader('Idempotency-Key')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'trash.restore')
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'patients.restore'));
    }

    private function payload(string $siteCode, string $siteName): array
    {
        return [
            'data' => [[
                'uuid' => '11111111-1111-4111-8111-111111111111',
                'category' => 'PATIENT',
                'category_label' => 'Patient',
                'category_icon' => 'users',
                'title' => 'RAKOTO Soa',
                'reference' => 'PA-000001',
                'subtitle' => 'Patient standard',
                'deleted_at' => '2026-08-30T10:00:00+03:00',
                'deleted_by' => 'Réception',
                'delete_reason' => 'Dossier créé en double',
                'can_restore' => true,
            ]],
            'meta' => [
                'site' => ['code' => $siteCode, 'name' => $siteName],
                'summary' => [
                    'total' => 1,
                    'displayed' => 1,
                    'limited' => false,
                    'categories' => [
                        'PATIENT' => 1,
                        'CATALOG_ITEM' => 0,
                        'ADDRESS_ENTRY' => 0,
                        'MUTUAL_ORGANIZATION' => 0,
                        'CASH_REGISTER' => 0,
                    ],
                ],
            ],
        ];
    }
}
