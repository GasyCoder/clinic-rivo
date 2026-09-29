<?php

namespace Tests\Feature\StaffAccess;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\ProfessionalMailboxProvision;
use App\Models\Role;
use App\Models\StaffAccessNotice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\NewEmployeesAwaitingAccess;
use App\Services\Notifications\AttentionDigest;
use App\Services\Notifications\NotificationCenter;
use App\Services\StaffAccess\StaffAccessWatcher;
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
 * ADR-197 / ADR-202 — côté portail : l'accès d'un employé créé en un geste
 * (adresse chez l'hébergeur, puis compte sur le site), sans aucun mot de passe
 * rendu ni transmis, et le Super Admin prévenu des employés qui attendent. Aucun
 * appel réel : hébergeur et site simulés.
 */
class PortalStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://abyssin.test:2083';

    private const SITE = 'https://a.test/api/v1/super-admin';

    private const EMPLOYEE = '22222222-2222-4222-8222-222222222222';

    private const MAILBOX = '11111111-1111-4111-8111-111111111111';

    private const HANDOVER = '33333333-3333-4333-8333-333333333333';

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
                'domain' => 'cliniquesaintgeorges.mg',
                'hosting' => ['url' => self::HOST, 'user' => 'flbe4406', 'token' => 'SECRET-TOKEN', 'quota_mb' => 1024, 'timeout' => 5],
            ],
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->superAdmin = $this->superAdmin();
        Cache::flush();
    }

    public function test_one_gesture_creates_the_box_then_the_account_without_any_password(): void
    {
        $grants = [];
        Http::fake([
            self::SITE.'/staff-access/employees/'.self::EMPLOYEE => Http::response(['data' => $this->employee()]),
            self::SITE.'/staff-access/grant' => function (Request $request) use (&$grants) {
                $grants[] = $request->data();

                return $request['dry_run'] ?? false
                    ? Http::response(['message' => 'Prêt à créer.'])
                    : Http::response(['message' => 'Accès de Hery RABE créé.', 'data' => [
                        'user' => ['uuid' => 'u-1', 'name' => 'Hery RABE', 'email' => 'hery.rabe@cliniquesaintgeorges.mg'],
                        'handover' => ['uuid' => self::HANDOVER, 'status' => 'DRAFT'],
                    ]], 201);
            },
            self::SITE.'/professional-mailboxes' => Http::response(['data' => $this->mailbox()], 201),
            self::SITE.'/professional-mailboxes/'.self::MAILBOX => Http::response(['data' => $this->mailbox()]),
            self::HOST.'/execute/Email/add_pop' => Http::response(['status' => 1]),
            self::SITE.'/professional-mailboxes/'.self::MAILBOX.'/activate' => Http::response(['data' => $this->mailbox('ACTIVE')]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/staff-access/A/grant', $this->grantPayload())
            ->assertCreated()
            ->assertJsonPath('email', 'hery.rabe@cliniquesaintgeorges.mg')
            ->assertJsonPath('handover.uuid', self::HANDOVER)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('renewed');

        // Le site a d'abord vérifié le compte, puis l'a créé : jamais de mot de passe transmis.
        $this->assertCount(2, $grants);
        $this->assertTrue($grants[0]['dry_run']);
        foreach ($grants as $grant) {
            $this->assertArrayNotHasKey('password', $grant);
            $this->assertArrayNotHasKey('mailbox_shares_password', $grant);
        }
        $this->assertSame('hery.rabe@cliniquesaintgeorges.mg', $grants[1]['email']);
        $this->assertSame('hery.rabe@cliniquesaintgeorges.mg', $grants[1]['mailbox_address']);

        // La boîte reçoit un mot de passe aléatoire que personne ne voit ; il n'est gardé nulle part.
        $hostPassword = null;
        Http::assertSent(function (Request $request) use (&$hostPassword) {
            if (! str_ends_with($request->url(), '/add_pop')) {
                return false;
            }
            $hostPassword = $request['password'];

            return is_string($hostPassword) && strlen($hostPassword) >= 16;
        });
        foreach ([AuditLog::query()->get(), ProfessionalMailboxProvision::query()->get(), UserNotification::query()->get()] as $rows) {
            $this->assertStringNotContainsString($hostPassword, json_encode($rows->toArray()));
        }
    }

    public function test_nothing_touches_the_host_when_the_site_refuses_the_account(): void
    {
        Http::fake([
            self::SITE.'/staff-access/employees/'.self::EMPLOYEE => Http::response(['data' => $this->employee()]),
            self::SITE.'/staff-access/grant' => Http::response([
                'message' => 'Cette adresse sert déjà à un autre compte RIVO.',
                'errors' => ['email' => ['Cette adresse sert déjà à un autre compte RIVO.']],
            ], 422),
            self::HOST.'/*' => Http::response(['status' => 1]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/staff-access/A/grant', $this->grantPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette adresse sert déjà à un autre compte RIVO.')
            ->assertJsonPath('errors.email.0', 'Cette adresse sert déjà à un autre compte RIVO.');

        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::HOST));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/professional-mailboxes'));
    }

    public function test_an_employee_who_already_has_an_account_is_refused_before_any_write(): void
    {
        Http::fake([
            self::SITE.'/staff-access/employees/'.self::EMPLOYEE => Http::response(['data' => $this->employee(['has_account' => true])]),
            '*' => Http::response(['status' => 1]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/staff-access/A/grant', $this->grantPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cet employé a déjà un compte RIVO.');

        Http::assertSentCount(1);
    }

    public function test_an_address_already_open_is_kept_as_it_is(): void
    {
        $grants = [];
        Http::fake([
            self::SITE.'/staff-access/employees/'.self::EMPLOYEE => Http::response(['data' => $this->employee([
                'mailbox' => ['uuid' => self::MAILBOX, 'status' => 'ACTIVE', 'address' => 'hery.rabe@cliniquesaintgeorges.mg'],
            ])]),
            self::SITE.'/staff-access/grant' => function (Request $request) use (&$grants) {
                $grants[] = $request->data();

                return Http::response(['message' => 'ok', 'data' => ['user' => ['name' => 'Hery RABE'], 'handover' => ['uuid' => self::HANDOVER]]], 201);
            },
            self::HOST.'/*' => Http::response(['status' => 1]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/staff-access/A/grant', [...$this->grantPayload(), 'local_part' => 'ignore.moi'])
            ->assertCreated()
            ->assertJsonPath('email', 'hery.rabe@cliniquesaintgeorges.mg');

        // Elle recevra le mot de passe que l'employé choisira : rien ne change chez l'hébergeur d'ici là.
        $this->assertSame('hery.rabe@cliniquesaintgeorges.mg', end($grants)['email']);
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), self::HOST));
    }

    public function test_a_suspended_address_is_never_reused_silently(): void
    {
        Http::fake([
            self::SITE.'/staff-access/employees/'.self::EMPLOYEE => Http::response(['data' => $this->employee([
                'mailbox' => ['uuid' => self::MAILBOX, 'status' => 'SUSPENDED', 'address' => 'hery.rabe@cliniquesaintgeorges.mg'],
            ])]),
            '*' => Http::response(['status' => 1]),
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/staff-access/A/grant', $this->grantPayload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'L’adresse hery.rabe@cliniquesaintgeorges.mg est suspendue : réactivez-la dans « Emails professionnels », puis recommencez.');

        Http::assertSentCount(1);
    }

    public function test_opening_the_page_warns_the_other_super_admins_once(): void
    {
        $colleague = $this->superAdmin();
        $this->fakeSiteListing([$this->pendingRow(self::EMPLOYEE, 'Hery RABE')]);

        $this->actingAs($this->superAdmin)->get('/super-admin/staff-access')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SuperAdmin/StaffAccess/Index')
                ->where('sites.0.code', 'A')
                ->where('sites.0.pending.0.name', 'Hery RABE')
                ->where('sites.0.receivers', 2)
                ->where('hosting.ready', true)
                ->where('can.create', true));

        // Celui qui regarde déjà la liste n'a pas besoin d'être prévenu ; son collègue, si.
        $this->assertSame(0, UserNotification::query()->for($this->superAdmin)->count());
        $notification = UserNotification::query()->for($colleague)->sole();
        $this->assertSame(NewEmployeesAwaitingAccess::class, $notification->type);
        $this->assertSame('staff_access.pending', $notification->data['kind']);
        $this->assertStringContainsString('Hery RABE', json_encode($notification->data));
        $this->assertSame('/super-admin/staff-access?site=A', $notification->data['url']);

        // Relire la liste ne prévient pas deux fois pour le même employé.
        $this->actingAs($colleague)->get('/super-admin/staff-access')->assertOk();
        $this->assertSame(1, UserNotification::query()->count());
        $this->assertSame(1, StaffAccessNotice::query()->count());
    }

    public function test_the_scheduled_sync_warns_every_super_admin_of_new_employees_only(): void
    {
        $colleague = $this->superAdmin();
        $this->fakeSiteListing([$this->pendingRow(self::EMPLOYEE, 'Hery RABE')]);

        $this->artisan('rivo:staff-access:sync')->expectsOutputToContain('Notification envoyée pour 1 site(s).')->assertSuccessful();
        $this->assertSame(1, UserNotification::query()->for($this->superAdmin)->count());
        $this->assertSame(1, UserNotification::query()->for($colleague)->count());

        $this->artisan('rivo:staff-access:sync')->expectsOutputToContain('Aucun nouvel employé à signaler.')->assertSuccessful();
        $this->assertSame(2, UserNotification::query()->count());

        // Le compte du portail sait combien attendent, sans relire les sites à l'ouverture de la cloche.
        $digest = app(AttentionDigest::class)->forUser($this->superAdmin);
        $this->assertSame('staff_access', $digest['items'][0]['key']);
        $this->assertSame(1, $digest['items'][0]['count']);
    }

    public function test_a_notification_is_marked_handled_once_its_employees_have_their_access(): void
    {
        $other = User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
        $second = '55555555-5555-4555-8555-555555555555';
        $watcher = app(StaffAccessWatcher::class);

        $watcher->sync($this->superAdmin, [$this->listing([$this->pendingRow(self::EMPLOYEE, 'Hery RABE'), $this->pendingRow($second, 'Soa RAKOTO')])]);
        $notification = UserNotification::query()->for($other)->sole();
        $this->assertSame([self::EMPLOYEE, $second], $notification->data['meta']['employee_uuids']);

        // L'un a reçu son accès, un nouveau est arrivé : « 1 sur 2 attend encore ».
        $newcomer = '66666666-6666-4666-8666-666666666666';
        $watcher->sync($this->superAdmin, [$this->listing([$this->pendingRow($second, 'Soa RAKOTO'), $this->pendingRow($newcomer, 'Vola RABE')])]);
        $this->assertSame('1 sur 2 attend encore', app(NotificationCenter::class)->present($notification->fresh())['resolution']['label']);
        $this->assertNull($notification->fresh()->read_at);

        // Plus aucun des deux n'attend : « Traité », et lue.
        $watcher->sync($this->superAdmin, [$this->listing([$this->pendingRow($newcomer, 'Vola RABE')])]);
        $handled = $notification->fresh();
        $this->assertSame('done', app(NotificationCenter::class)->present($handled)['resolution']['state']);
        $this->assertNotNull($handled->read_at);
    }

    public function test_an_older_notification_finds_its_employees_in_the_notices_of_the_same_reading(): void
    {
        $second = '55555555-5555-4555-8555-555555555555';
        $other = User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
        foreach ([self::EMPLOYEE, $second] as $uuid) {
            StaffAccessNotice::query()->create(['site_code' => 'A', 'employee_uuid' => $uuid, 'noticed_at' => now()]);
        }
        // Une notification d'avant : ni la liste des employés, ni le nombre qui attend encore.
        $other->notify(new NewEmployeesAwaitingAccess('A', 'Ambondromamy', ['Hery RABE', 'Soa RAKOTO'], 2));
        $notification = UserNotification::query()->for($other)->sole();
        $notification->forceFill(['data' => array_replace_recursive($notification->data, ['meta' => ['employee_uuids' => [], 'waiting' => null]])])->save();

        // Les deux sont servis, un autre attend encore sur le site : elle est tout de même traitée.
        app(StaffAccessWatcher::class)->resolve('A', ['66666666-6666-4666-8666-666666666666']);

        $this->assertArrayHasKey('resolved_at', $notification->fresh()->data['meta']);
    }

    public function test_the_sync_does_nothing_on_a_clinic_site(): void
    {
        config(['rivo.site.type' => 'clinic']);
        Http::fake();

        $this->artisan('rivo:staff-access:sync')->expectsOutputToContain('ne tourne que sur le portail')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_the_bell_rereads_the_sites_after_the_response_and_at_most_every_two_minutes(): void
    {
        $colleague = $this->superAdmin();
        $this->fakeSiteListing([$this->pendingRow(self::EMPLOYEE, 'Hery RABE')]);

        $this->actingAs($this->superAdmin)->getJson('/notifications/resume')->assertOk();
        $this->assertSame(1, UserNotification::query()->for($colleague)->count());
        Http::assertSentCount(1);

        // Pendant deux minutes, la cloche ne programme aucune nouvelle lecture des sites.
        // (En test, Laravel rejoue à chaque requête les tâches « après la réponse » déjà
        // enregistrées : on vérifie donc le verrou lui-même, et qu'aucune notification ne double.)
        $this->assertTrue(Cache::has('staff-access.sync-throttle'));
        $this->assertFalse(Cache::add('staff-access.sync-throttle', true, 120));
        $this->actingAs($this->superAdmin)->getJson('/notifications/resume')->assertOk();
        $this->assertSame(1, UserNotification::query()->for($colleague)->count());
    }

    public function test_a_super_admin_without_the_right_sees_neither_the_page_nor_the_actions(): void
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->permissions()->attach(Permission::query()->where('name', 'staff_access.view')->value('id'), ['effect' => 'deny']);
        Http::fake();

        $this->actingAs($user)->get('/super-admin/staff-access')->assertForbidden();
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    public function test_the_portal_relays_the_designation_of_who_hands_accesses_over(): void
    {
        $user = '44444444-4444-4444-8444-444444444444';
        Http::fake([self::SITE.'/staff-access/receivers' => Http::response([
            'message' => 'Voahangy recevra et remettra les accès du personnel.',
            'data' => ['receivers' => 1, 'accounts' => []],
        ])]);

        $this->actingAs($this->superAdmin)
            ->postJson('/super-admin/staff-access/A/receivers', ['user_uuid' => $user])
            ->assertOk()
            ->assertJsonPath('receivers', 1);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/staff-access/receivers') && $request['user_uuid'] === $user);
    }

    private function grantPayload(): array
    {
        return [
            'handover_uuid' => self::HANDOVER,
            'employee_uuid' => self::EMPLOYEE,
            'local_part' => 'hery.rabe',
            'role_id' => Role::query()->where('code', 'RECEPTION')->value('id'),
        ];
    }

    /** @return array<string, mixed> */
    private function employee(array $overrides = []): array
    {
        return [
            ...$this->pendingRow(self::EMPLOYEE, 'Hery RABE'),
            'has_account' => false,
            'active' => true,
            'waived' => false,
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function pendingRow(string $uuid, string $name): array
    {
        return [
            'uuid' => $uuid,
            'employee_number' => 'RH-001',
            'name' => $name,
            'job_title' => 'Réceptionniste',
            'department' => 'Accueil',
            'mailbox' => null,
            'suggestion' => 'hery.rabe',
        ];
    }

    /** @return array<string, mixed> */
    private function mailbox(string $status = 'REQUESTED'): array
    {
        return [
            'uuid' => self::MAILBOX,
            'address' => 'hery.rabe@cliniquesaintgeorges.mg',
            'status' => $status,
            'status_label' => $status,
            'open' => true,
            'to_suspend' => false,
            'employee' => ['uuid' => self::EMPLOYEE, 'name' => 'Hery RABE', 'employee_number' => 'RH-001', 'active' => true],
        ];
    }

    /** @param list<array<string, mixed>> $pending */
    private function fakeSiteListing(array $pending): void
    {
        Http::fake([self::SITE.'/staff-access' => Http::response(['data' => [
            'pending' => $pending,
            'waived' => [],
            'handovers' => [],
            'roles' => [],
            'receivers' => 2,
        ]])]);
    }

    /** @param list<array<string, mixed>> $pending */
    private function listing(array $pending): array
    {
        return ['ok' => true, 'site' => ['code' => 'A', 'name' => 'Ambondromamy'], 'data' => ['pending' => $pending]];
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('code', 'SUPER_ADMIN')->value('id')]);
    }
}
