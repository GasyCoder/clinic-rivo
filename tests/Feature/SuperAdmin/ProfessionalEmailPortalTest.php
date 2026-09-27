<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProfessionalMailboxProvision;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-190 — le portail crée les boîtes chez l'hébergeur (cPanel), puis le dit
 * au site. Aucun appel réel : l'hébergeur et le site sont simulés.
 */
class ProfessionalEmailPortalTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://abyssin.test:2083';

    private const MAILBOX = '11111111-1111-4111-8111-111111111111';

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'admin',
            'rivo.site.code' => 'ADMIN',
            'rivo.site.name' => 'Super Administration',
            'rivo.site_api.retry_times' => 1,
            'rivo.clinics' => [
                ['code' => 'A', 'name' => 'Ambondromamy', 'url' => 'https://a.test', 'api_url' => 'https://a.test/api/v1', 'api_token' => 'a-token'],
            ],
            'rivo.professional_email' => [
                'domain' => 'cbdc.mg',
                'hosting' => ['url' => self::HOST, 'user' => 'flbe4406', 'token' => 'SECRET-TOKEN', 'quota_mb' => 1024, 'timeout' => 5],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
    }

    public function test_the_page_lists_the_requests_of_each_site_and_never_shows_the_token(): void
    {
        Http::fake(['https://a.test/api/v1/super-admin/professional-mailboxes' => Http::response([
            'data' => [$this->mailbox()],
            'meta' => ['domain' => 'cbdc.mg', 'summary' => ['requested' => 1]],
        ])]);

        $this->actingAs($this->superAdmin)->get('/super-admin/professional-emails')
            ->assertOk()
            ->assertDontSee('SECRET-TOKEN')
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/ProfessionalEmails/Index')
                ->where('sites.0.data.0.address', 'hery.rabe@cbdc.mg')
                ->where('hosting.configured', true)
                ->where('hosting.server', 'abyssin.test')
                ->where('hosting.domain', 'cbdc.mg'));
    }

    public function test_create_makes_the_box_at_the_host_then_activates_it_on_the_site(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            self::HOST.'/execute/Email/add_pop' => Http::response(['status' => 1, 'errors' => null, 'data' => 'hery.rabe+cbdc.mg']),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX.'/activate' => Http::response(['data' => $this->mailbox('ACTIVE')]),
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'hery.rabe'])
            ->assertOk()
            ->assertJsonPath('address', 'hery.rabe@cbdc.mg')
            ->assertJsonPath('confirmed', true);

        $password = $response->json('password');
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(16, strlen($password));

        Http::assertSent(fn (Request $request) => $request->url() === self::HOST.'/execute/Email/add_pop'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'cpanel flbe4406:SECRET-TOKEN')
            && $request['email'] === 'hery.rabe' && $request['domain'] === 'cbdc.mg'
            && $request['password'] === $password && (int) $request['quota'] === 1024);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/activate') && $request['address'] === 'hery.rabe@cbdc.mg');

        $provision = ProfessionalMailboxProvision::query()->sole();
        $this->assertNotNull($provision->site_confirmed_at);

        // Le mot de passe n'est nulle part : ni en base, ni dans l'audit.
        $this->assertStringNotContainsString($password, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString($password, json_encode($provision->toArray()));
    }

    public function test_a_lost_site_confirmation_never_creates_the_box_twice(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            self::HOST.'/execute/Email/add_pop' => Http::response(['status' => 1]),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX.'/activate' => Http::sequence()
                ->push(['message' => 'Erreur interne'], 500)
                ->push(['data' => $this->mailbox('ACTIVE')]),
        ]);

        $first = $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'hery.rabe'])
            ->assertOk()
            ->assertJsonPath('confirmed', false);
        $this->assertNotNull($first->json('password'), 'la boîte existe : son mot de passe est montré même si le site n’a pas confirmé');

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'autre.chose'])
            ->assertOk()
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('address', 'hery.rabe@cbdc.mg')
            ->assertJsonPath('password', null);

        Http::assertSentCount(5); // lecture + création + activation refusée, puis lecture + activation — jamais une seconde création
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'add_pop'))->count());
    }

    /** « Nouvelle adresse » : la demande est enregistrée sur le site, puis créée comme depuis « Demandes ». */
    public function test_new_address_requests_on_the_site_then_creates_the_box(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes' => Http::response(['data' => $this->mailbox()], 201),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            self::HOST.'/execute/Email/add_pop' => Http::response(['status' => 1]),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX.'/activate' => Http::response(['data' => $this->mailbox('ACTIVE')]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/direct', ['employee_uuid' => '22222222-2222-4222-8222-222222222222', 'local_part' => 'hery.rabe'])
            ->assertOk()
            ->assertJsonPath('mailbox_uuid', self::MAILBOX)
            ->assertJsonPath('confirmed', true);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://a.test/api/v1/super-admin/professional-mailboxes'
            && $request['employee_uuid'] === '22222222-2222-4222-8222-222222222222' && $request['local_part'] === 'hery.rabe');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'add_pop'));
    }

    public function test_new_address_refused_by_the_host_leaves_the_request_pending(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes' => Http::response(['data' => $this->mailbox()], 201),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            self::HOST.'/execute/Email/add_pop' => Http::response('Unauthorized', 401),
        ]);

        $message = $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/direct', ['employee_uuid' => '22222222-2222-4222-8222-222222222222', 'local_part' => 'hery.rabe'])
            ->assertUnprocessable()
            ->json('message');

        $this->assertStringContainsString('La demande est enregistrée, mais la boîte n’a pas été créée', $message);
        $this->assertStringContainsString('refusé l’accès', $message);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/activate'));
    }

    public function test_a_host_refusal_is_shown_and_the_site_is_not_touched(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            self::HOST.'/execute/Email/add_pop' => Http::response(['status' => 0, 'errors' => ['The account hery.rabe@cbdc.mg already exists!']]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'hery.rabe'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'L’hébergeur a refusé : The account hery.rabe@cbdc.mg already exists!');

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/activate'));
        $this->assertSame(0, ProfessionalMailboxProvision::query()->count());
    }

    /**
     * L'offre o2switch n'ouvre pas les jetons, et refuse l'authentification Basic sur
     * l'API : le portail ouvre une session avec le mot de passe, appelle l'API par
     * cette session, puis la ferme.
     */
    public function test_without_a_token_the_cpanel_password_opens_a_session(): void
    {
        config(['rivo.professional_email.hosting.token' => '', 'rivo.professional_email.hosting.password' => 'MOT-DE-PASSE-CPANEL']);
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX.'/activate' => Http::response(['data' => $this->mailbox('ACTIVE')]),
            ...$this->cpanelSession(['add_pop' => Http::response(['status' => 1])]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'hery.rabe'])
            ->assertOk()->assertJsonPath('confirmed', true);

        Http::assertSent(fn (Request $request) => $request->url() === self::HOST.'/login/?login_only=1'
            && $request['user'] === 'flbe4406' && $request['pass'] === 'MOT-DE-PASSE-CPANEL');
        Http::assertSent(fn (Request $request) => $request->url() === self::HOST.'/cpsess0123456789/execute/Email/add_pop'
            && $request->hasHeader('Cookie', 'cpsession=flbe4406%3aSESSION')
            && ! $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'MOT-DE-PASSE-CPANEL'));

        $this->actingAs($this->superAdmin)->get('/super-admin/professional-emails')
            ->assertDontSee('MOT-DE-PASSE-CPANEL')
            ->assertInertia(fn (Assert $page) => $page->where('hosting.auth_mode', 'password'));
    }

    /** La connexion cPanel est l'étape lente : une session sert aux opérations suivantes. */
    public function test_the_cpanel_session_is_reused_by_the_next_operation(): void
    {
        config(['rivo.professional_email.hosting.token' => '', 'rivo.professional_email.hosting.password' => 'MOT-DE-PASSE-CPANEL']);
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox('ACTIVE')]),
            ...$this->cpanelSession(['passwd_pop' => Http::response(['status' => 1])]),
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/password')->assertOk();
        }

        $this->assertSame(1, $this->sentTo('/login/'), 'une seule connexion pour deux opérations');
        $this->assertSame(2, $this->sentTo('/execute/Email/passwd_pop'));
        $this->assertSame(0, $this->sentTo('/logout/'), 'la session gardée n’est pas fermée');
    }

    /** Une session expirée répond 401 sans rien exécuter : une nouvelle, et l'appel rejoué une seule fois. */
    public function test_an_expired_session_is_replaced_once_and_the_call_replayed(): void
    {
        config(['rivo.professional_email.hosting.token' => '', 'rivo.professional_email.hosting.password' => 'MOT-DE-PASSE-CPANEL']);
        Cache::put('rivo:cpanel-session:'.sha1(self::HOST.'|flbe4406'), Crypt::encrypt(['path' => '/cpsess0123456789', 'cookie' => 'flbe4406%3aEXPIREE']), now()->addMinutes(10));
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox('ACTIVE')]),
            ...$this->cpanelSession(['passwd_pop' => Http::sequence()
                ->push('Access Denied', 401)
                ->push(['status' => 1])
                ->push('Access Denied', 401)
                ->push('Access Denied', 401)]),
        ]);

        $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/password')->assertOk();
        $this->assertSame(1, $this->sentTo('/login/'));
        $this->assertSame(2, $this->sentTo('/execute/Email/passwd_pop'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'passwd_pop') && $request->hasHeader('Cookie', 'cpsession=flbe4406%3aSESSION'));

        // La nouvelle session refuse aussi : aucun troisième essai, et le refus est dit.
        $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/password')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'refusé la connexion'));
        $this->assertSame(4, $this->sentTo('/execute/Email/passwd_pop'));
    }

    /** À l'ouverture d'une fenêtre, la connexion se fait d'avance : « Créer » ne l'attend plus. */
    public function test_the_connection_is_prepared_before_the_creation(): void
    {
        config(['rivo.professional_email.hosting.token' => '', 'rivo.professional_email.hosting.password' => 'MOT-DE-PASSE-CPANEL']);
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX.'/activate' => Http::response(['data' => $this->mailbox('ACTIVE')]),
            ...$this->cpanelSession(['add_pop' => Http::response(['status' => 1])]),
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/prepare')->assertOk();
        }
        $this->assertSame(1, $this->sentTo('/login/'), 'préparer deux fois n’ouvre qu’une session');
        $this->assertSame(0, $this->sentTo('/execute/'), 'préparer ne touche à aucune boîte');

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'hery.rabe'])
            ->assertOk()->assertJsonPath('confirmed', true);
        $this->assertSame(1, $this->sentTo('/login/'), 'la création reprend la session préparée');
        $this->assertSame(1, ProfessionalMailboxProvision::query()->where('address', 'hery.rabe@cbdc.mg')->count());
    }

    public function test_preparing_needs_a_right_that_reaches_the_host_and_does_nothing_with_a_token(): void
    {
        Http::fake();
        $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/prepare')->assertOk();
        Http::assertNothingSent();

        $role = Role::query()->where('code', 'SUPER_ADMIN')->sole();
        $role->permissions()->detach(Permission::query()->where('name', 'like', 'professional_emails.%')
            ->where('name', '!=', 'professional_emails.view')->pluck('id'));

        $this->actingAs($this->superAdmin->fresh())->postJson('/super-admin/professional-emails/prepare')->assertForbidden();
    }

    /** Sans session gardée (0 minute), elle est fermée après la réponse. */
    public function test_without_kept_sessions_each_operation_closes_its_own(): void
    {
        config([
            'rivo.professional_email.hosting.token' => '',
            'rivo.professional_email.hosting.password' => 'MOT-DE-PASSE-CPANEL',
            'rivo.professional_email.hosting.session_minutes' => 0,
        ]);
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox('ACTIVE')]),
            ...$this->cpanelSession(['passwd_pop' => Http::response(['status' => 1])]),
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/password')->assertOk();
        }

        $this->assertSame(2, $this->sentTo('/login/'));
        $this->assertSame(2, $this->sentTo('/logout/'));
        $this->assertNull(Cache::get('rivo:cpanel-session:'.sha1(self::HOST.'|flbe4406')));
    }

    /** Mauvais mot de passe, ou double authentification : rien n'est tenté chez l'hébergeur. */
    public function test_a_refused_cpanel_login_attempts_nothing(): void
    {
        config(['rivo.professional_email.hosting.token' => '', 'rivo.professional_email.hosting.password' => 'MAUVAIS']);
        Http::fake([
            self::HOST.'/login/*' => Http::sequence()
                ->push(['status' => 0, 'message' => 'invalid_login'], 401)
                ->push(['status' => 1, 'redirect' => '/tfa'], 200),
            self::HOST.'/*' => Http::response(['status' => 1]),
        ]);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/check')
                ->assertUnprocessable()
                ->assertJsonPath('message', fn (string $message) => str_contains($message, 'refusé la connexion'));
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/execute/'));
    }

    public function test_the_token_wins_when_both_are_set(): void
    {
        config(['rivo.professional_email.hosting.password' => 'MOT-DE-PASSE-CPANEL']);
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox('ACTIVE')]),
            self::HOST.'/execute/Email/passwd_pop' => Http::response(['status' => 1]),
        ]);

        $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/password')->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'passwd_pop')
            && $request->hasHeader('Authorization', 'cpanel flbe4406:SECRET-TOKEN'));
    }

    /** « Tester la connexion » : une lecture seule, rien n'est créé ni enregistré. */
    public function test_the_connection_check_only_reads_at_the_host(): void
    {
        Http::fake([self::HOST.'/execute/Email/list_pops' => Http::sequence()
            ->push(['status' => 1, 'data' => [['email' => 'a@cbdc.mg'], ['email' => 'b@cbdc.mg']]])
            ->push('Unauthorized', 401)]);

        $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/check')
            ->assertOk()
            ->assertJsonPath('message', 'Connexion à l’hébergeur réussie : 2 boîtes sur le compte.');

        $this->actingAs($this->superAdmin)->postJson('/super-admin/professional-emails/check')
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'refusé l’accès'));

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'add_pop') || str_contains($request->url(), 'a.test'));
        $this->assertSame(0, ProfessionalMailboxProvision::query()->count());
    }

    public function test_nothing_is_attempted_without_hosting_access(): void
    {
        config(['rivo.professional_email.hosting.token' => '']);
        Http::fake();

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'hery.rabe'])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_a_departed_employee_box_is_suspended_at_the_host_first_then_on_the_site(): void
    {
        $this->createdProvision();
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox('ACTIVE')]),
            self::HOST.'/execute/Email/suspend_login' => Http::response(['status' => 1]),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX.'/suspend' => Http::response(['message' => 'Adresse suspendue.', 'data' => $this->mailbox('SUSPENDED')]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/professional-emails/A/'.self::MAILBOX.'/suspend', ['reason' => 'Départ de la clinique'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === self::HOST.'/execute/Email/suspend_login' && $request['email'] === 'hery.rabe@cbdc.mg');
        $this->assertNotNull(ProfessionalMailboxProvision::query()->sole()->host_suspended_at);
    }

    public function test_a_retried_suspension_does_not_ask_the_host_again(): void
    {
        $this->createdProvision(['host_suspended_at' => now()]);
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox('ACTIVE')]),
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX.'/suspend' => Http::response(['data' => $this->mailbox('SUSPENDED')]),
        ]);

        $this->actingAs($this->superAdmin)
            ->post('/super-admin/professional-emails/A/'.self::MAILBOX.'/suspend', ['reason' => 'Départ de la clinique'])
            ->assertSessionHasNoErrors();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'suspend_login'));
    }

    public function test_a_new_password_is_set_at_the_host_and_shown_once(): void
    {
        Http::fake([
            'https://a.test/api/v1/super-admin/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox('ACTIVE')]),
            self::HOST.'/execute/Email/passwd_pop' => Http::response(['status' => 1]),
        ]);

        $password = $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/password')
            ->assertOk()
            ->json('password');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'passwd_pop') && $request['password'] === $password && $request['email'] === 'hery.rabe');
        $this->assertSame('professional_email.password_reset', AuditLog::query()->latest('id')->value('action'));
    }

    /** Chaque geste garde son droit : un refus individuel (ADR-033) l'emporte même pour un Super Admin. */
    public function test_the_actions_keep_their_own_permission(): void
    {
        $denied = Permission::query()->whereIn('name', ['professional_emails.create', 'professional_emails.update'])->pluck('id');
        $this->superAdmin->permissions()->attach($denied->mapWithKeys(fn (int $id) => [$id => ['effect' => 'deny']])->all());
        Http::fake();

        $this->actingAs($this->superAdmin->fresh())->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/create', ['local_part' => 'hery.rabe'])->assertForbidden();
        $this->actingAs($this->superAdmin->fresh())->postJson('/super-admin/professional-emails/A/'.self::MAILBOX.'/password')->assertForbidden();

        Http::assertNothingSent();
    }

    private function createdProvision(array $attributes = []): void
    {
        ProfessionalMailboxProvision::query()->create([
            'site_code' => 'A',
            'mailbox_uuid' => self::MAILBOX,
            'address' => 'hery.rabe@cbdc.mg',
            'created_by' => $this->superAdmin->id,
            'host_created_at' => now(),
            'site_confirmed_at' => now(),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function mailbox(string $status = 'REQUESTED'): array
    {
        return [
            'uuid' => self::MAILBOX,
            'address' => 'hery.rabe@cbdc.mg',
            'status' => $status,
            'status_label' => $status,
            'open' => true,
            'to_suspend' => false,
            'employee' => ['uuid' => 'e-1', 'name' => 'Hery RABE', 'employee_number' => 'RH-001', 'active' => true],
        ];
    }

    /**
     * Les réponses d'une session cPanel : connexion, appels à l'API, fermeture.
     *
     * @param  array<string, mixed>  $calls  fonction UAPI => réponse
     * @return array<string, mixed>
     */
    private function cpanelSession(array $calls): array
    {
        $fakes = [self::HOST.'/login/*' => Http::response(
            ['status' => 1, 'security_token' => '/cpsess0123456789', 'redirect' => '/cpsess0123456789/frontend/o2switch/index.html'],
            200,
            ['Set-Cookie' => 'cpsession=flbe4406%3aSESSION; HttpOnly; SameSite=Lax; path=/; port=2083; secure'],
        )];
        foreach ($calls as $function => $response) {
            $fakes[self::HOST.'/cpsess0123456789/execute/Email/'.$function] = $response;
        }
        $fakes[self::HOST.'/cpsess0123456789/logout/'] = Http::response('', 200);

        return $fakes;
    }

    private function sentTo(string $path): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $path))->count();
    }
}
