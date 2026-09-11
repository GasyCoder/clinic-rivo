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

class DocumentTemplatesPortalTest extends TestCase
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

    public function test_only_super_admin_reaches_the_portal_workspace_which_fans_out_to_every_site(): void
    {
        Http::fake([
            'https://m.test/api/v1/super-admin/document-templates*' => Http::response(['data' => [
                'templates' => [], 'summary' => ['active' => 0, 'archived' => 0], 'document_types' => [],
            ]], 200),
            'https://a.test/api/v1/super-admin/document-templates*' => Http::response(['data' => [
                'templates' => [['uuid' => 'x', 'name' => 'Attestation', 'document_type' => 'ATTESTATION']],
                'summary' => ['active' => 1, 'archived' => 0], 'document_types' => ['ATTESTATION'],
            ]], 200),
            'https://b.test/api/v1/super-admin/document-templates*' => Http::response(['message' => 'Indisponible'], 503),
        ]);

        $administration = User::factory()->create([
            'role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id'),
        ]);
        // ADR-027: an operational-role account is incoherent with the admin
        // deployment — account.deployment logs it out and redirects, before
        // the can:super_admin.portal.view gate is even reached.
        $this->actingAs($administration)->get('/super-admin/workspaces/document-templates')->assertRedirect('/login');

        $superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);
        $this->actingAs($superAdmin)->get('/super-admin/workspaces/document-templates')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SuperAdmin/DocumentTemplates/Index')
                ->has('sites', 3)
                ->where('sites.1.data.summary.active', 1)
                ->has('dataContexts', 3));
    }

    public function test_create_refuses_an_unconfigured_site_before_any_content_is_composed(): void
    {
        config(['rivo.clinics' => [
            ['code' => 'M', 'name' => 'Mampikony', 'api_url' => '', 'api_token' => ''],
            ['code' => 'A', 'name' => 'Ambondromamy', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
        ]]);
        $superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);

        $this->actingAs($superAdmin)
            ->get('/super-admin/workspaces/document-templates/M/create')
            ->assertStatus(503);

        $this->actingAs($superAdmin)
            ->get('/super-admin/workspaces/document-templates/A/create')
            ->assertOk();
    }

    public function test_store_delegates_to_the_selected_site_with_the_actors_permissions(): void
    {
        Http::fake(['https://a.test/api/v1/super-admin/document-templates' => Http::response([
            'message' => 'Canevas créé sur le site.',
            'data' => ['uuid' => 'new-uuid', 'name' => 'Attestation de travail'],
        ], 201)]);
        $superAdmin = User::factory()->create([
            'role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id'),
        ]);

        $this->actingAs($superAdmin)->post('/super-admin/workspaces/document-templates', [
            'site_code' => 'A',
            'document_type' => 'ATTESTATION',
            'data_context' => 'EMPLOYEE_ONLY',
            'name' => 'Attestation de travail',
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => '<p>{{nom}}</p>',
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->url() === 'https://a.test/api/v1/super-admin/document-templates'
            && $request->method() === 'POST'
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0] ?? '', 'document_templates.create')
            && $request->header('X-Rivo-Actor-Name')[0] === $superAdmin->name);
    }
}
