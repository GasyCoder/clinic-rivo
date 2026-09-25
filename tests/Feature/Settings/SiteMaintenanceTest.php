<?php

namespace Tests\Feature\Settings;

use App\Actions\Settings\SetSiteMaintenanceAction;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\SiteMaintenance;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Services\Settings\SiteMaintenanceState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-193 — un site en maintenance : réglée depuis le portail par l'API du site,
 * maintenant ou programmée, levée à la main ou à sa fin prévue ; seul le droit
 * dédié la traverse, et la connexion comme l'API restent ouvertes.
 */
class SiteMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/super-admin/app-settings/maintenance';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-25 14:00'));
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
        ]);
    }

    public function test_putting_a_site_in_maintenance_needs_its_own_right_and_is_audited(): void
    {
        // Régler les paramètres n'est pas fermer le site.
        $this->withHeaders($this->headers(['settings.update']))
            ->putJson(self::URL, $this->now())
            ->assertForbidden();
        $this->assertSame(0, SiteMaintenance::query()->count());

        $actor = (string) Str::uuid();
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION], $actor))
            ->putJson(self::URL, [...$this->now(), 'ends_at' => '2026-09-25 16:00'])
            ->assertOk()
            ->assertJsonPath('message', 'Le site est en maintenance.')
            ->assertJsonPath('data.maintenance.current.state', 'ACTIVE')
            ->assertJsonPath('data.maintenance.current.created_by', 'Direction centrale');

        $maintenance = SiteMaintenance::sole();
        $this->assertTrue($maintenance->starts_at->equalTo(now()), 'le début est l’heure du serveur');
        $this->assertSame($actor, $maintenance->external_created_by_uuid);
        $this->assertNull($maintenance->created_by, 'aucun compte local n’est fabriqué pour le Super Admin');
        $this->assertTrue(AuditLog::query()->where('action', 'app_maintenance.start')->where('external_actor_uuid', $actor)->exists());
    }

    public function test_a_scheduled_maintenance_warns_then_closes_then_reopens_on_its_own(): void
    {
        $user = User::factory()->withRole()->create();
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->putJson(self::URL, ['mode' => 'scheduled', 'title' => 'Mise à jour', 'message' => 'Nouvelle version.', 'starts_at' => '2026-09-25 22:00', 'ends_at' => '2026-09-25 23:30'])
            ->assertOk()
            ->assertJsonPath('message', 'Maintenance programmée pour ce site.')
            ->assertJsonPath('data.maintenance.current.state', 'UPCOMING');
        $this->assertTrue(AuditLog::query()->where('action', 'app_maintenance.schedule')->exists());

        // Dans les 24 heures qui précèdent : le site est ouvert, et il prévient.
        $this->web($user)->get('/profil')->assertOk()->assertInertia(fn ($page) => $page
            ->where('site.maintenance.state', 'UPCOMING')
            ->where('site.maintenance.title', 'Mise à jour'));

        // À l'heure dite, il se ferme de lui-même.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 22:01'));
        $this->web($user)->get('/profil')->assertStatus(503)->assertInertia(fn ($page) => $page
            ->component('Maintenance')
            ->where('notice.title', 'Mise à jour')
            ->where('notice.message', 'Nouvelle version.'));

        // À sa fin prévue, il rouvre de lui-même : aucun traitement planifié n'est nécessaire.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 23:30'));
        $this->web($user)->get('/profil')->assertOk()->assertInertia(fn ($page) => $page->where('site.maintenance', null));
        $this->assertSame(SiteMaintenance::STATE_ENDED, SiteMaintenance::sole()->state());
    }

    public function test_a_maintenance_far_ahead_does_not_warn_yet(): void
    {
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->putJson(self::URL, ['mode' => 'scheduled', 'title' => 'Mise à jour', 'starts_at' => '2026-09-30 22:00'])
            ->assertOk();

        $this->web(User::factory()->withRole()->create())
            ->get('/profil')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('site.maintenance', null));
    }

    public function test_an_impossible_window_is_refused(): void
    {
        $headers = fn () => $this->headers([SiteMaintenanceState::MANAGE_PERMISSION]);

        foreach ([
            ['mode' => 'scheduled', 'starts_at' => null],
            ['mode' => 'scheduled', 'starts_at' => '2026-09-25 13:00'],
            ['mode' => 'scheduled', 'starts_at' => '2026-09-25 22:00', 'ends_at' => '2026-09-25 21:00'],
            ['mode' => 'now', 'ends_at' => '2026-09-25 13:00'],
            ['mode' => 'plus-tard'],
            ['title' => ''],
            ['title' => str_repeat('a', 121)],
            ['message' => str_repeat('a', 2001)],
        ] as $bad) {
            $this->withHeaders($headers())
                ->putJson(self::URL, [...$this->now(), ...$bad])
                ->assertUnprocessable();
        }

        $this->assertSame(0, SiteMaintenance::query()->count());
    }

    public function test_editing_a_running_maintenance_keeps_its_start_and_never_opens_a_second_one(): void
    {
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))->putJson(self::URL, $this->now())->assertOk();
        $start = SiteMaintenance::sole()->starts_at;

        $this->travelTo(CarbonImmutable::parse('2026-09-25 14:20'));
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->putJson(self::URL, [...$this->now(), 'message' => 'Encore une demi-heure.', 'ends_at' => '2026-09-25 15:00'])
            ->assertOk();

        $maintenance = SiteMaintenance::sole();
        $this->assertTrue($maintenance->starts_at->equalTo($start), 'une maintenance en cours garde son heure de début');
        $this->assertSame('Encore une demi-heure.', $maintenance->message);
        $this->assertSame('2026-09-25 15:00', $maintenance->ends_at->format('Y-m-d H:i'));
        $this->assertTrue(AuditLog::query()->where('action', 'app_maintenance.update')->exists());
    }

    public function test_lifting_reopens_the_site_and_the_maintenance_stays_in_history(): void
    {
        $user = User::factory()->withRole()->create();
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))->putJson(self::URL, $this->now())->assertOk();
        $this->web($user)->get('/profil')->assertStatus(503);

        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->postJson(self::URL.'/lift', ['reason' => 'Intervention terminée'])
            ->assertOk()
            ->assertJsonPath('message', 'Maintenance levée : le site est de nouveau ouvert.')
            ->assertJsonPath('data.maintenance.current', null)
            ->assertJsonPath('data.maintenance.history.0.state', 'LIFTED')
            ->assertJsonPath('data.maintenance.history.0.lift_reason', 'Intervention terminée')
            ->assertJsonPath('data.maintenance.history.0.lifted_by', 'Direction centrale');

        $this->web($user)->get('/profil')->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'app_maintenance.lift')->exists());

        // Plus rien à lever : dit, pas une erreur brute.
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->postJson(self::URL.'/lift')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('maintenance');
    }

    public function test_cancelling_a_scheduled_maintenance_is_its_own_trace(): void
    {
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->putJson(self::URL, ['mode' => 'scheduled', 'title' => 'Mise à jour', 'starts_at' => '2026-09-26 06:00'])
            ->assertOk();

        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->postJson(self::URL.'/lift')
            ->assertOk()
            ->assertJsonPath('message', 'Maintenance programmée annulée.');

        $this->assertTrue(AuditLog::query()->where('action', 'app_maintenance.cancel')->exists());
    }

    public function test_only_the_dedicated_right_works_through_a_closed_site(): void
    {
        $this->closeSite(['ends_at' => '2026-09-25 16:00']);

        $this->web(User::factory()->withRole()->create())
            ->get('/profil')
            ->assertStatus(503)
            ->assertHeader('Retry-After', (string) (2 * 3600))
            ->assertInertia(fn ($page) => $page
                ->component('Maintenance')
                ->where('notice.title', 'Mise à jour du système')
                ->where('notice.ends_at', CarbonImmutable::parse('2026-09-25 16:00')->toIso8601String()));

        $technician = User::factory()->withRole('MAINTENANCE')->create();
        $technician->role->permissions()->attach(Permission::query()->firstOrCreate(['name' => SiteMaintenanceState::BYPASS_PERMISSION])->id);

        $this->web($technician)
            ->get('/profil')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('site.maintenance.state', 'ACTIVE')
                ->where('site.maintenance.bypassing', true));
    }

    public function test_the_login_stays_open_and_says_the_site_is_closed(): void
    {
        $this->closeSite();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Login')
                ->where('site.maintenance.state', 'ACTIVE')
                ->where('site.maintenance.bypassing', false));

        $this->get('/robots.txt')->assertOk();

        // Un visiteur non connecté voit la page de maintenance, qui mène à la connexion réservée.
        $this->get('/')->assertStatus(503)->assertInertia(fn ($page) => $page->component('Maintenance')->where('auth.user', null));
    }

    public function test_a_call_expecting_json_gets_json(): void
    {
        $this->closeSite();

        $this->web(User::factory()->withRole()->create())
            ->getJson('/profil')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Mise à jour du système')
            ->assertJsonPath('maintenance.title', 'Mise à jour du système');
    }

    public function test_the_site_api_stays_open_to_the_portal(): void
    {
        $this->closeSite();

        $this->withHeaders($this->headers(['settings.view']))
            ->getJson('/api/v1/super-admin/app-settings')
            ->assertOk()
            ->assertJsonPath('data.maintenance.current.state', 'ACTIVE');
    }

    public function test_a_message_left_empty_falls_back_to_the_proposed_one(): void
    {
        $this->closeSite(['message' => '']);

        $this->web(User::factory()->withRole()->create())
            ->get('/profil')
            ->assertStatus(503)
            ->assertInertia(fn ($page) => $page->where('notice.message', SiteMaintenanceState::DEFAULT_MESSAGE));
    }

    public function test_the_portal_is_never_put_in_maintenance(): void
    {
        $role = User::factory()->withRole('SUPPORT')->make()->role;
        $role->permissions()->attach(Permission::query()->where('name', SiteMaintenanceState::MANAGE_PERMISSION)->value('id'));
        $user = User::factory()->create(['role_id' => $role->id]);
        config(['rivo.site.type' => 'admin']);

        try {
            app(SetSiteMaintenanceAction::class)->execute($this->now(), CatalogActor::fromUser($user));
            $this->fail('Le portail ne se met pas en maintenance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('site_code', $exception->errors());
        }

        $this->assertSame(0, SiteMaintenance::query()->count());
        $this->assertFalse(SiteMaintenanceState::applies());
    }

    public function test_a_database_without_the_table_never_closes_the_site_and_says_so_on_write(): void
    {
        Schema::drop('site_maintenances');

        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->putJson(self::URL, $this->now())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['maintenance' => 'La maintenance n’est pas encore installée sur ce site : ses migrations doivent d’abord être jouées (php artisan migrate).']);

        $this->web(User::factory()->withRole()->create())
            ->get('/profil')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('site.maintenance', null));
    }

    /** @param array<string, mixed> $values */
    private function closeSite(array $values = []): void
    {
        $this->withHeaders($this->headers([SiteMaintenanceState::MANAGE_PERMISSION]))
            ->putJson(self::URL, [...$this->now(), 'title' => 'Mise à jour du système', ...$values])
            ->assertOk();
        $this->flushHeaders();
    }

    /** Une requête d'un compte du site : sans les en-têtes de l'API du portail. */
    private function web(User $user): static
    {
        $this->flushHeaders();

        return $this->actingAs($user);
    }

    /** @return array<string, mixed> */
    private function now(): array
    {
        return ['mode' => 'now', 'title' => 'Maintenance en cours', 'message' => 'Le site revient vite.'];
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions, ?string $actorUuid = null): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => $actorUuid ?? (string) Str::uuid(),
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }
}
