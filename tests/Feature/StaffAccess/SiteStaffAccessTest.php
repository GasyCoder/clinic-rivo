<?php

namespace Tests\Feature\StaffAccess;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\StaffAccessHandover;
use App\Models\StaffAccessHandoverItem;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\StaffAccessReady;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-197 / ADR-202 — côté site : l'API que le portail appelle pour créer l'accès
 * du personnel — sans aucun mot de passe —, l'annonce au RH, et son suivi : qui
 * s'est connecté, qui a laissé passer le délai, et la réouverture d'un délai.
 */
class SiteStaffAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_UUID = '6d3f4a8e-1c2b-4d5e-9f60-7a8b9c0d1e2f';

    private const ALL = ['staff_access.view', 'staff_access.create', 'users.create', 'roles.assign'];

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
            'rivo.professional_email.domain' => 'cliniquesaintgeorges.mg',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, ProfessionalProfileSeeder::class]);
        $this->hr = User::factory()->create(['role_id' => $this->roleId('ADMINISTRATION')]);
    }

    public function test_the_portal_reads_who_waits_for_an_access_and_nothing_else(): void
    {
        $waiting = $this->employee('RH-001', ['first_name' => 'Zéphyr', 'last_name' => 'Andrianina']);
        $this->employee('RH-002', ['user_id' => User::factory()->create(['role_id' => $this->roleId('RECEPTION')])->id]);
        $this->employee('RH-003', ['active' => false]);
        $this->employee('RH-004')->delete();
        $waived = $this->employee('RH-005', ['access_waived_at' => now(), 'access_waived_reason' => 'Entretien']);

        $this->withHeaders($this->headers(['staff_access.view']))->getJson('/api/v1/super-admin/staff-access')
            ->assertOk()
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonPath('data.pending.0.uuid', $waiting->uuid)
            ->assertJsonPath('data.pending.0.suggestion', 'zephyr.andrianina')
            ->assertJsonPath('data.waived.0.uuid', $waived->uuid)
            ->assertJsonPath('data.receivers', 1)
            ->assertJsonPath('meta.domain', 'cliniquesaintgeorges.mg')
            ->assertJsonPath('meta.activation_days', 14);

        $this->withHeaders($this->headers([]))->getJson('/api/v1/super-admin/staff-access')->assertForbidden();
    }

    public function test_a_dry_run_checks_everything_and_writes_nothing(): void
    {
        $employee = $this->employee('RH-001');

        $this->grant($employee, ['dry_run' => true])->assertOk()->assertJsonPath('message', 'Prêt à créer.');
        $this->assertNull($employee->fresh()->user_id);
        $this->assertSame(0, StaffAccessHandover::query()->count());

        // Un rôle qui a des profils exige le profil ; une adresse déjà prise est refusée.
        $this->grant($employee, ['dry_run' => true, 'role_id' => $this->roleId('NURSE')])->assertUnprocessable()->assertJsonValidationErrors('professional_profile_id');
        User::factory()->create(['email' => 'hery.rabe@cliniquesaintgeorges.mg']);
        $this->grant($employee, ['dry_run' => true])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_granting_creates_the_linked_account_without_any_password_awaiting_its_first_login(): void
    {
        $employee = $this->employee('RH-001');
        $handover = (string) Str::uuid();

        // Aucun mot de passe ne voyage : le refus le nomme.
        $this->grant($employee, ['handover_uuid' => $handover, 'password' => 'Kp7#mQ2x!Vb9zR4t'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertNull($employee->fresh()->user_id);

        $this->grant($employee, ['handover_uuid' => $handover])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'hery.rabe@cliniquesaintgeorges.mg')
            ->assertJsonPath('data.handover.status', 'DRAFT')
            ->assertJsonPath('data.handover.items.0.state', 'WAITING')
            ->assertJsonMissingPath('data.handover.items.0.secret');

        $user = User::query()->where('email', 'hery.rabe@cliniquesaintgeorges.mg')->sole();
        $this->assertSame($user->id, $employee->fresh()->user_id);
        $this->assertSame('Hery RABE', $user->name);
        $this->assertNull($user->activated_at);
        $this->assertTrue($user->activation_open_until->between(now()->addDays(14)->subMinute(), now()->addDays(14)->addMinute()));
        $this->assertTrue($user->awaitsActivation(), 'il choisira son mot de passe à sa première connexion');

        $item = StaffAccessHandoverItem::query()->sole();
        $this->assertNull(DB::table('staff_access_handover_items')->value('secret'), 'rien n’est gardé pour la remise');
        $this->assertTrue($item->mailbox_shares_password, 'sa boîte recevra le mot de passe qu’il choisira');
        $grant = AuditLog::query()->where('action', 'staff_access.grant')->sole();
        $this->assertSame(self::ACTOR_UUID, $grant->external_actor_uuid);
        $this->assertArrayHasKey('first_login_until', $grant->new_values);

        // L'employé n'attend plus ; un second accès est refusé.
        $this->withHeaders($this->headers(['staff_access.view']))->getJson('/api/v1/super-admin/staff-access')->assertJsonCount(0, 'data.pending');
        $this->grant($employee, ['email' => 'autre@cliniquesaintgeorges.mg'])->assertUnprocessable()->assertJsonValidationErrors('employee_uuid');
    }

    public function test_creating_an_account_needs_the_account_rights_too(): void
    {
        $employee = $this->employee('RH-001');

        $this->withHeaders($this->headers(['staff_access.create']))
            ->postJson('/api/v1/super-admin/staff-access/grant', $this->payload($employee))
            ->assertForbidden();
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_sending_notifies_the_hr_accounts_once_and_starts_the_first_login_window(): void
    {
        $handover = $this->granted();
        $reception = User::factory()->create(['role_id' => $this->roleId('RECEPTION')]);

        // Envoyé cinq jours après la création : le délai part de l'envoi.
        $this->travel(5)->days();
        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/handovers/{$handover->uuid}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'WAITING')
            ->assertJsonPath('data.counts.waiting', 1);
        $this->assertTrue(User::query()->where('email', 'hery.rabe@cliniquesaintgeorges.mg')->sole()->activation_open_until->greaterThan(now()->addDays(13)));

        $this->assertSame(1, UserNotification::query()->for($this->hr)->where('type', StaffAccessReady::class)->count());
        $this->assertSame(0, UserNotification::query()->for($reception)->count(), 'seulement les comptes qui annoncent les accès');
        $notification = UserNotification::query()->for($this->hr)->sole();
        $this->assertStringContainsString('choisit son mot de passe', $notification->data['body']);
        $this->assertSame("/administration/staff-access/{$handover->uuid}", $notification->data['url']);

        // Envoyer deux fois ne notifie pas deux fois ; une remise envoyée ne reçoit plus d'accès.
        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/handovers/{$handover->uuid}/send")->assertOk();
        $this->assertSame(1, UserNotification::query()->for($this->hr)->count());
        $this->grant($this->employee('RH-009', ['first_name' => 'Soa']), ['handover_uuid' => $handover->uuid, 'email' => 'soa.rabe@cliniquesaintgeorges.mg'])
            ->assertUnprocessable()->assertJsonValidationErrors('handover_uuid');
    }

    public function test_sending_is_refused_when_no_account_can_receive(): void
    {
        $handover = $this->granted();
        $this->hr->forceFill(['active' => false])->save();

        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/handovers/{$handover->uuid}/send")
            ->assertUnprocessable()->assertJsonValidationErrors('handover');
        $this->assertNull($handover->fresh()->sent_at);
    }

    public function test_the_hr_sees_who_connected_and_reopens_a_late_first_login(): void
    {
        $handover = $this->granted();

        // Pas encore envoyée : le RH ne la voit pas.
        $this->actingAs($this->hr)->get("/administration/staff-access/{$handover->uuid}")->assertNotFound();

        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/handovers/{$handover->uuid}/send")->assertOk();
        $item = StaffAccessHandoverItem::query()->sole();

        $this->actingAs($this->hr)->get("/administration/staff-access/{$handover->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/StaffAccess/Show')
                ->where('handover.status', 'WAITING')
                ->where('handover.items.0.login_email', 'hery.rabe@cliniquesaintgeorges.mg')
                ->where('handover.items.0.state', 'WAITING')
                ->where('loginUrl', route('login'))
                ->missing('handover.items.0.secret'));

        // Encore ouverte : rien à rouvrir.
        $this->actingAs($this->hr)->post("/administration/staff-access/{$handover->uuid}/items/{$item->uuid}/reopen")->assertSessionHasErrors('item');

        // Le délai passe sans connexion.
        $this->travel(15)->days();
        $this->actingAs($this->hr)->get('/administration/staff-access')->assertInertia(fn (Assert $page) => $page
            ->where('counts.a-rouvrir', 1)
            ->where('people.expired', 1)
            ->where('handovers.data.0.status', 'TO_REOPEN'));

        $this->actingAs($this->hr)->post("/administration/staff-access/{$handover->uuid}/items/{$item->uuid}/reopen")->assertRedirect()->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'hery.rabe@cliniquesaintgeorges.mg')->sole();
        $this->assertTrue($user->awaitsActivation());
        $this->assertTrue(AuditLog::query()->where('action', 'staff_access.activation.reopen')->where('entity_id', $user->id)->exists());

        // Il se connecte : la remise est terminée.
        $user->forceFill(['activated_at' => now(), 'activation_open_until' => null])->save();
        $this->actingAs($this->hr)->get("/administration/staff-access/{$handover->uuid}")->assertInertia(fn (Assert $page) => $page
            ->where('handover.status', 'COMPLETE')
            ->where('handover.items.0.state', 'ACTIVATED'));
        $this->actingAs($this->hr)->post("/administration/staff-access/{$handover->uuid}/items/{$item->uuid}/reopen")->assertSessionHasErrors('item');

        // Une ligne d'une autre remise ne se rouvre pas par celle-ci.
        $other = $this->receivedHandover(['Soa Rakoto' => 'expired'], ['sent_at' => now()]);
        $this->actingAs($this->hr)->post("/administration/staff-access/{$handover->uuid}/items/{$other->items->first()->uuid}/reopen")->assertNotFound();
    }

    public function test_the_portal_follows_the_hand_over_and_can_reopen_it(): void
    {
        $handover = $this->granted();
        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/handovers/{$handover->uuid}/send")->assertOk();
        $relay = [...self::ALL, 'staff_access.receive', 'employees.view'];

        // Les écrans RH servis au portail (ADR-187) montrent la remise, telle quelle.
        $this->withHeaders($this->headers($relay))->getJson("/api/v1/super-admin/hr/staff-access/{$handover->uuid}")
            ->assertOk()
            ->assertJsonPath('component', 'Administration/StaffAccess/Show')
            ->assertJsonPath('props.handover.items.0.state', 'WAITING');

        // Aucun mot de passe à protéger : rouvrir un délai se fait aussi depuis le portail.
        $this->travel(15)->days();
        $item = StaffAccessHandoverItem::query()->sole();
        $this->withHeaders($this->headers($relay))->postJson("/api/v1/super-admin/hr/staff-access/{$handover->uuid}/items/{$item->uuid}/reopen")->assertOk();
        $this->assertTrue(User::query()->where('email', 'hery.rabe@cliniquesaintgeorges.mg')->sole()->awaitsActivation());
    }

    public function test_the_hr_list_puts_what_needs_a_gesture_first_and_filters_by_card_and_by_employee(): void
    {
        $waiting = $this->receivedHandover(['Soa Rakoto' => 'waiting'], ['sent_at' => now()->subHour()]);
        $late = $this->receivedHandover(['Vola Rabe' => 'expired', 'Hery Andria' => 'activated'], ['sent_at' => now()->subDays(20)]);
        $done = $this->receivedHandover(['Nirina Ravelo' => 'activated'], ['sent_at' => now()->subDays(2)]);
        $mixed = $this->receivedHandover(['Tahina Rabe' => 'waiting', 'Lova Rabe' => 'activated'], ['sent_at' => now()->subDays(3)]);
        $draft = $this->receivedHandover(['Pas Envoyé' => 'waiting'], ['sent_at' => null]);

        // Tout, sauf le brouillon : un délai dépassé d'abord, puis ce qui attend, puis le reste.
        $this->actingAs($this->hr)->get('/administration/staff-access')->assertInertia(fn (Assert $page) => $page
            ->component('Administration/StaffAccess/Index')
            ->where('counts', ['toutes' => 4, 'a-rouvrir' => 1, 'en-attente' => 2, 'terminees' => 1])
            ->where('people', ['waiting' => 2, 'expired' => 1])
            ->where('filters', ['vue' => 'toutes', 'q' => ''])
            ->where('nextDeadline', fn (?string $next) => $next !== null)
            ->has('handovers.data', 4)
            ->where('handovers.data.0.uuid', $late->uuid)
            ->where('handovers.data.0.status', 'TO_REOPEN')
            ->where('handovers.data.0.counts', ['total' => 2, 'activated' => 1, 'waiting' => 0, 'expired' => 1])
            ->where('handovers.data.1.uuid', $waiting->uuid)
            ->where('handovers.data.2.uuid', $mixed->uuid)
            ->where('handovers.data.3.uuid', $done->uuid)
            ->missing('handovers.data.0.items.0.secret'));

        // La carte est le filtre ; les vues sont exclusives.
        foreach (['a-rouvrir' => [$late], 'en-attente' => [$waiting, $mixed], 'terminees' => [$done]] as $view => $expected) {
            $this->actingAs($this->hr)->get("/administration/staff-access?vue={$view}")->assertInertia(fn (Assert $page) => $page
                ->where('filters.vue', $view)
                ->where('handovers.data', fn ($data) => collect($data)->pluck('uuid')->all() === collect($expected)->pluck('uuid')->all()));
        }

        // La recherche trouve une remise par son employé ; les comptes la suivent.
        $this->actingAs($this->hr)->get('/administration/staff-access?q=rabe')->assertInertia(fn (Assert $page) => $page
            ->where('counts', ['toutes' => 2, 'a-rouvrir' => 1, 'en-attente' => 1, 'terminees' => 0])
            ->where('handovers.data', fn ($data) => collect($data)->pluck('uuid')->all() === [$late->uuid, $mixed->uuid]));

        // Une vue inconnue ne filtre rien ; un « % » cherché est un caractère, pas un joker.
        $this->actingAs($this->hr)->get('/administration/staff-access?vue=nimporte')->assertInertia(fn (Assert $page) => $page->where('filters.vue', 'toutes')->has('handovers.data', 4));
        $this->actingAs($this->hr)->get('/administration/staff-access?q=%25')->assertInertia(fn (Assert $page) => $page->has('handovers.data', 0));
        $this->assertNotContains($draft->uuid, [$waiting->uuid, $late->uuid, $done->uuid, $mixed->uuid]);
    }

    public function test_only_accounts_that_hand_accesses_over_open_the_pages(): void
    {
        $handover = $this->granted();
        $reception = User::factory()->create(['role_id' => $this->roleId('RECEPTION')]);

        $this->actingAs($reception)->get('/administration/staff-access')->assertForbidden();
        $item = StaffAccessHandoverItem::query()->sole();
        $this->actingAs($reception)->post("/administration/staff-access/{$handover->uuid}/items/{$item->uuid}/reopen")->assertForbidden();
    }

    public function test_an_employee_without_need_leaves_the_list_and_comes_back(): void
    {
        $employee = $this->employee('RH-001');

        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/employees/{$employee->uuid}/waive", ['reason' => 'Agent d’entretien'])->assertOk();
        $this->assertNotNull($employee->fresh()->access_waived_at);
        $this->assertSame('Direction centrale', $employee->fresh()->access_waived_by_name);

        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/employees/{$employee->uuid}/unwaive")->assertOk();
        $this->assertNull($employee->fresh()->access_waived_at);

        $this->withHeaders($this->headers(self::ALL))->postJson("/api/v1/super-admin/staff-access/employees/{$employee->uuid}/waive", ['reason' => ''])->assertUnprocessable();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Une remise reçue, et pour chaque employé l'état de sa première connexion.
     *
     * @param  array<string, 'waiting'|'expired'|'activated'>  $people
     */
    private function receivedHandover(array $people, array $attributes): StaffAccessHandover
    {
        $handover = StaffAccessHandover::query()->create(['sent_by_name' => 'Super Admin test', ...$attributes]);

        foreach ($people as $name => $state) {
            $user = User::factory()->create([
                'role_id' => $this->roleId('RECEPTION'),
                'name' => $name,
                'email' => Str::slug($name, '.').'@cliniquesaintgeorges.mg',
                'last_login_at' => null,
            ]);
            $user->forceFill(match ($state) {
                'waiting' => ['activated_at' => null, 'activation_open_until' => now()->addDays(10)],
                'expired' => ['activated_at' => null, 'activation_open_until' => now()->subDay()],
                default => ['activated_at' => now()->subDay(), 'activation_open_until' => null],
            })->save();

            StaffAccessHandoverItem::query()->create([
                'staff_access_handover_id' => $handover->id,
                'user_id' => $user->id,
                'employee_name' => $name,
                'employee_number' => 'EMP-'.$user->id,
                'login_email' => $user->email,
                'role_label' => 'Réception',
            ]);
        }

        return $handover->fresh('items');
    }

    private function granted(): StaffAccessHandover
    {
        $this->grant($this->employee('RH-001'))->assertCreated();

        return StaffAccessHandover::query()->sole();
    }

    private function grant(Employee $employee, array $overrides = []): TestResponse
    {
        return $this->withHeaders($this->headers(self::ALL))->postJson('/api/v1/super-admin/staff-access/grant', [...$this->payload($employee), ...$overrides]);
    }

    /** @return array<string, mixed> */
    private function payload(Employee $employee): array
    {
        return [
            'handover_uuid' => '11111111-2222-4333-8444-555555555555',
            'employee_uuid' => $employee->uuid,
            'email' => 'hery.rabe@cliniquesaintgeorges.mg',
            'mailbox_address' => 'hery.rabe@cliniquesaintgeorges.mg',
            'role_id' => $this->roleId('RECEPTION'),
        ];
    }

    public function test_the_super_admin_designates_who_hands_accesses_over_keeping_the_account_s_other_exceptions(): void
    {
        $reception = User::factory()->create(['role_id' => $this->roleId('RECEPTION')]);
        $exportId = (int) DB::table('permissions')->where('name', 'patients.export')->value('id');
        $receiveId = (int) DB::table('permissions')->where('name', 'staff_access.receive')->value('id');
        DB::table('user_permissions')->insert([
            ['user_id' => $reception->id, 'permission_id' => $exportId, 'effect' => 'deny', 'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $reception->id, 'permission_id' => $receiveId, 'effect' => 'deny', 'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->hr->forceFill(['active' => false])->save();

        // La liste offerte au Super Admin : les comptes actifs, jamais sans le droit d'attribuer.
        $accounts = collect($this->withHeaders($this->headers(['staff_access.view', 'permissions.assign']))->getJson('/api/v1/super-admin/staff-access')
            ->assertOk()->assertJsonPath('data.receivers', 0)->json('data.accounts'));
        $this->assertTrue($accounts->pluck('uuid')->contains($reception->uuid));
        $this->assertFalse($accounts->pluck('uuid')->contains($this->hr->uuid), 'un compte désactivé ne se désigne pas');
        $this->withHeaders($this->headers(['staff_access.view']))->getJson('/api/v1/super-admin/staff-access')->assertOk()->assertJsonPath('data.accounts', []);

        // Sans le droit d'attribuer une exception : refus.
        $this->withHeaders($this->headers(['staff_access.create']))->postJson('/api/v1/super-admin/staff-access/receivers', ['user_uuid' => $reception->uuid])->assertForbidden();
        $this->withHeaders($this->headers(['staff_access.create', 'permissions.assign']))->postJson('/api/v1/super-admin/staff-access/receivers', ['user_uuid' => $this->hr->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors('user_uuid');

        $this->withHeaders($this->headers(['staff_access.create', 'permissions.assign']))->postJson('/api/v1/super-admin/staff-access/receivers', ['user_uuid' => $reception->uuid])
            ->assertOk()
            ->assertJsonPath('data.receivers', 1);

        $this->assertTrue($reception->fresh()->can('staff_access.receive'));
        // Son autre exception est intacte ; le refus sur ce droit a cédé à la désignation.
        $this->assertSame('deny', DB::table('user_permissions')->where('user_id', $reception->id)->where('permission_id', $exportId)->value('effect'));
        $this->assertSame('allow', DB::table('user_permissions')->where('user_id', $reception->id)->where('permission_id', $receiveId)->value('effect'));
        $this->assertTrue(AuditLog::query()->where('action', 'user.permissions.assign')->where('entity_id', $reception->id)->exists());
    }

    private function employee(string $number, array $attributes = []): Employee
    {
        $employee = Employee::query()->create([
            'employee_number' => $number,
            'last_name' => 'RABE',
            'first_name' => 'Hery',
            'sex' => 'M',
            'active' => true,
            ...collect($attributes)->except(['access_waived_at', 'access_waived_reason'])->all(),
        ]);

        if (isset($attributes['access_waived_at'])) {
            $employee->forceFill(['access_waived_at' => $attributes['access_waived_at'], 'access_waived_reason' => $attributes['access_waived_reason'] ?? null])->save();
        }

        return $employee;
    }

    private function roleId(string $code): int
    {
        return (int) Role::query()->where('code', $code)->value('id');
    }

    /** @param array<int, string> $permissions */
    private function headers(array $permissions): array
    {
        return [
            'Authorization' => 'Bearer clinic-test-token',
            'X-Request-UUID' => (string) Str::uuid(),
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Rivo-Actor-UUID' => self::ACTOR_UUID,
            'X-Rivo-Actor-Name' => 'Direction centrale',
            'X-Rivo-Actor-Permissions' => implode(',', $permissions),
        ];
    }
}
