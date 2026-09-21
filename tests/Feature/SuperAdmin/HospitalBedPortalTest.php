<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-164 — le portail règle les lits de chaque site par l'API de ce site,
 * jamais par sa base ; un site injoignable n'empêche pas les autres.
 */
class HospitalBedPortalTest extends TestCase
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
                ['code' => 'M', 'name' => 'Mampikony', 'url' => 'https://m.test', 'api_url' => 'https://m.test/api/v1', 'api_token' => 'm-token'],
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
                ['code' => 'B', 'name' => 'Boriziny', 'url' => 'https://b.test', 'api_url' => null, 'api_token' => null],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
    }

    public function test_the_page_reads_each_site_through_its_api(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/hospital-beds*' => Http::response([
                'data' => [['uuid' => 'service-m', 'name' => 'Médecine interne', 'care_level' => 'STANDARD', 'rooms' => []]],
                'meta' => ['summary' => ['services' => 1, 'rooms' => 0, 'beds' => 0, 'free' => 0, 'occupied' => 0, 'out_of_service' => 0]],
            ], 200),
            'https://a.test/api/v1/super-admin/hospital-beds*' => Http::response(['message' => 'Indisponible'], 503),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/hospital-beds')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/HospitalBeds/Index')
                ->has('sites', 3)
                ->where('sites.0.ok', true)
                ->where('sites.0.data.0.name', 'Médecine interne')
                ->where('sites.1.ok', false)
                ->where('sites.2.status', 'UNCONFIGURED'));
    }

    public function test_a_room_command_goes_to_the_chosen_site_with_the_central_identity(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/hospital-beds/*' => Http::response(['message' => 'Chambre « Box » créée avec 3 lits.'], 201),
        ]);
        $service = '0b8f3c38-1d8e-4d7f-9b52-8f1c1f7f0a11';

        $this->actingAs($this->superAdmin)
            ->post("/super-admin/hospital-beds/A/services/{$service}/rooms", ['name' => 'Box', 'bed_count' => 3])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Chambre « Box » créée avec 3 lits.');

        Http::assertSent(fn (Request $request) => $request->url() === "https://a.test/api/v1/super-admin/hospital-beds/services/{$service}/rooms"
            && $request->method() === 'POST'
            && $request['bed_count'] === 3
            && $request->hasHeader('Idempotency-Key')
            && $request->header('X-Rivo-Actor-UUID')[0] === $this->superAdmin->uuid
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0], 'hospital_beds.create'));
    }

    public function test_a_refusal_from_the_site_comes_back_on_its_field(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/hospital-beds/*' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => ['bed' => ['Ce lit est occupé : installez d’abord le patient dans un autre lit.']],
            ], 422),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/hospital-beds/A/beds/0b8f3c38-1d8e-4d7f-9b52-8f1c1f7f0a11/out-of-service', ['reason' => 'Sommier cassé'])
            ->assertSessionHasErrors(['bed' => 'Ce lit est occupé : installez d’abord le patient dans un autre lit.']);
    }

    public function test_the_page_needs_its_permission(): void
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'hospital_beds.view')->value('id'));
        Cache::forget(Permission::CACHE_KEY);

        $this->actingAs($this->superAdmin->fresh())->get('/super-admin/hospital-beds')->assertForbidden();
    }
}
