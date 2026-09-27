<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\SuperAdmin\SiteHrGateway;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-187 — le Super Admin gère les Ressources humaines d'un site depuis le
 * portail : les écrans du site, relayés par son API, jamais par sa base.
 */
class SiteHumanResourcesPortalTest extends TestCase
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

    public function test_a_site_hr_screen_is_rendered_by_the_portal_with_its_own_addresses(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/hr/employees*' => Http::response([
                'component' => 'Administration/Employees/Index',
                'props' => [
                    'employees' => [
                        'data' => [['uuid' => 'e-1', 'employee_number' => 'RH-001', 'download_url' => 'https://a.test/administration/documents/d-1/download']],
                        'next_page_url' => 'https://a.test/api/v1/super-admin/hr/employees?page=2',
                    ],
                    'note' => 'Voir /administration-generale, sans lien',
                ],
                'url' => '/api/v1/super-admin/hr/employees',
            ], 200, ['Content-Type' => 'application/json', 'X-Inertia' => 'true']),
        ]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/rh/employees?status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Employees/Index')
                ->where('employees.data.0.employee_number', 'RH-001')
                ->where('employees.data.0.download_url', '/super-admin/sites/A/rh/documents/d-1/download')
                ->where('employees.next_page_url', '/super-admin/sites/A/rh/employees?page=2')
                ->where('note', 'Voir /administration-generale, sans lien')
                ->where('hrContext.base', '/super-admin/sites/A/rh')
                ->where('hrContext.site.name', 'Ambondromamy')
                ->where('hrContext.sites.2.configured', false));

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://a.test/api/v1/super-admin/hr/employees?status=active')
            && $request->hasHeader('Authorization', 'Bearer a-token')
            && $request->hasHeader('X-Rivo-Actor-UUID', $this->superAdmin->uuid)
            && str_contains($request->header('X-Rivo-Actor-Permissions')[0], 'employees.view'));
    }

    public function test_only_hr_screens_can_be_rendered(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['component' => 'SuperAdmin/Settings/Index', 'props' => []], 200)]);

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/rh/employees')->assertNotFound();
    }

    /**
     * Chaque écran rendu par une route RH doit s'afficher au portail : la page
     * Stages (ADR-194) avait été oubliée, et l'onglet « Stages » ouvrait un 404.
     */
    public function test_every_screen_served_by_the_hr_routes_is_allowed_on_the_portal(): void
    {
        preg_match_all('/^use (App\\\\Http\\\\Controllers\\\\[\w\\\\]+);/m', file_get_contents(base_path('routes/hr.php')), $uses);
        $components = collect($uses[1])
            ->map(fn (string $class) => (new \ReflectionClass($class))->getFileName())
            ->flatMap(function (string $file) {
                preg_match_all("/Inertia::render\\('([^']+)'/", file_get_contents($file), $found);

                return $found[1];
            })
            ->unique()
            ->values();

        $this->assertContains('Administration/Internships/Index', $components->all());
        $gateway = app(SiteHrGateway::class);
        $refused = $components->reject(fn (string $component) => $gateway->isHrScreen($component))->values()->all();
        $this->assertSame([], $refused, 'Écrans RH que le portail refuserait : '.implode(', ', $refused));
    }

    /** Un seul accueil RH par site : l'ancienne adresse `?site=A` mène à celui du site. */
    public function test_the_portal_hr_page_leads_a_chosen_site_to_its_own_hr_home(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/workspaces/hr?site=A')
            ->assertRedirect('/super-admin/sites/A/rh');
        $this->actingAs($this->superAdmin)->get('/super-admin/workspaces/hr?site=Z')->assertOk();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/super-admin/hr'));
    }

    /** ADR-190 — les adresses professionnelles se gèrent sur la page du portail, qui a l'accès à l'hébergeur. */
    public function test_the_relayed_professional_email_page_leads_to_the_portal_page(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/a/rh/professional-emails')
            ->assertRedirect('/super-admin/professional-emails?site=A');
        Http::assertNothingSent();
    }

    public function test_a_write_follows_the_site_redirection_back_into_the_portal(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/hr/employees' => Http::response([
                'redirect' => '/administration/employees/e-9', 'status' => 'Dossier Employé RH-009 créé.', 'error' => null,
            ], 200),
        ]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/sites/A/rh/employees/create')
            ->post('/super-admin/sites/A/rh/employees', ['employee_number' => 'RH-009', 'last_name' => 'Rabe', 'active' => true])
            ->assertRedirect('/super-admin/sites/A/rh/employees/e-9')
            ->assertSessionHas('status', 'Dossier Employé RH-009 créé.');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key')
            && $request['employee_number'] === 'RH-009'
            && $request['active'] === true
            && $request->header('Referer')[0] === 'https://a.test/administration/employees/create');
    }

    public function test_a_redirection_outside_hr_leads_back_to_the_hr_home(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['redirect' => '/patients', 'status' => null, 'error' => null], 200)]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/sites/A/rh/leave/l-1/approve', [])
            ->assertRedirect('/super-admin/sites/A/rh');
    }

    public function test_site_validation_errors_come_back_on_the_form(): void
    {
        Http::fake(['https://a.test/*' => Http::response([
            'message' => 'Le champ nom est obligatoire.',
            'errors' => ['last_name' => ['Le champ nom est obligatoire.']],
        ], 422)]);

        $this->actingAs($this->superAdmin)
            ->from('/super-admin/sites/A/rh/employees/create')
            ->post('/super-admin/sites/A/rh/employees', ['last_name' => ''])
            ->assertRedirect('/super-admin/sites/A/rh/employees/create')
            ->assertSessionHasErrors(['last_name' => 'Le champ nom est obligatoire.']);
    }

    public function test_a_preview_read_by_fetch_is_returned_as_the_site_sent_it(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['errors' => ['starts_on' => ['obligatoire']]], 422)]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/sites/A/rh/leave/preview', ['employee_uuid' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.starts_on.0', 'obligatoire');
    }

    public function test_a_file_is_relayed_to_the_browser(): void
    {
        Http::fake(['https://a.test/*' => Http::response('xlsx-bytes', 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="employes.xlsx"',
        ])]);

        $response = $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/rh/employees/export')->assertOk();

        $this->assertSame('xlsx-bytes', $response->getContent());
        $this->assertSame('attachment; filename="employes.xlsx"', $response->headers->get('Content-Disposition'));
    }

    public function test_an_upload_travels_as_multipart_with_the_method_spoofed(): void
    {
        Http::fake(['https://a.test/*' => Http::response(['redirect' => '/administration/employees/e-1', 'status' => 'Pièce ajoutée.', 'error' => null], 200)]);

        $this->actingAs($this->superAdmin)
            ->put('/super-admin/sites/A/rh/documents', [
                'employee_uuid' => 'e-1',
                'lines' => [['label' => 'Recto']],
                'file' => UploadedFile::fake()->create('cin.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect('/super-admin/sites/A/rh/employees/e-1');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->isMultipart()
            && collect($request->data())->contains(fn ($part) => $part['name'] === '_method' && $part['contents'] === 'PUT')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'lines[0][label]' && $part['contents'] === 'Recto')
            && collect($request->data())->contains(fn ($part) => $part['name'] === 'file' && $part['filename'] === 'cin.pdf'));
    }

    public function test_an_unconfigured_site_is_announced_without_calling_anything(): void
    {
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/B/rh')
            ->assertRedirect(route('super-admin.workspaces.hr'))
            ->assertSessionHas('error', 'L’URL ou le jeton API de Boriziny n’est pas configuré.');

        Http::assertNothingSent();
    }

    public function test_the_portal_refuses_an_account_denied_the_hr_space(): void
    {
        $this->superAdmin->permissions()->attach(
            Permission::query()->where('name', 'employees.view')->value('id'),
            ['effect' => 'deny', 'source' => 'MANUAL'],
        );
        Http::fake();

        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A/rh')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_the_old_hr_showcase_leads_to_the_real_hr_space(): void
    {
        $this->actingAs($this->superAdmin)->get('/super-admin/sites/A?module=HR')
            ->assertRedirect('/super-admin/sites/A/rh');
    }
}
