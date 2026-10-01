<?php

namespace Tests\Feature\Auth;

use App\Enums\HrReferenceType;
use App\Enums\ProfessionalMailboxStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\ProfessionalMailbox;
use App\Models\Role;
use App\Models\StaffAccessHandover;
use App\Models\StaffAccessHandoverItem;
use App\Models\User;
use App\Notifications\AccountActivatedMail;
use App\Notifications\StaffAccessActivated;
use App\Notifications\WelcomeToPlatform;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADR-202 — la première connexion : l'adresse d'abord ; un compte qui attend sa
 * première connexion salue la personne et lui fait choisir son mot de passe, qui
 * devient aussi celui de sa boîte ; tout autre compte demande le mot de passe.
 */
class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private const CHOSEN = 'Mon-Choix-2026!';

    private const HOST = 'https://abyssin.test:2083';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_the_address_alone_says_which_step_comes_next_without_telling_who_exists(): void
    {
        $vola = $this->awaiting();
        $ordinary = User::factory()->create(['role_id' => $this->roleId('RECEPTION'), 'email' => 'deja.la@cliniquesaintgeorges.mg']);

        $this->postJson('/login/identifier', ['email' => 'VOLA.RABE@cliniquesaintgeorges.mg '])
            ->assertOk()
            ->assertJsonPath('mode', 'activate')
            ->assertJsonPath('greeting.name', 'Vola RABE')
            ->assertJsonPath('greeting.first_name', 'Vola')
            ->assertJsonPath('greeting.job', 'médecin')
            ->assertJsonPath('greeting.site', 'Ambondromamy')
            ->assertJsonPath('greeting.mailbox', 'vola.rabe@cliniquesaintgeorges.mg')
            ->assertJsonMissingPath('greeting.password');

        // Un compte déjà utilisé, une adresse inconnue : la même réponse.
        foreach ([$ordinary->email, 'personne@cliniquesaintgeorges.mg'] as $email) {
            $this->postJson('/login/identifier', ['email' => $email])->assertOk()->assertExactJson(['mode' => 'password']);
        }

        // Désactivé, délai passé : plus de choix du mot de passe par l'adresse seule.
        $vola->forceFill(['activation_open_until' => now()->subMinute()])->save();
        $this->postJson('/login/identifier', ['email' => $vola->email])->assertExactJson(['mode' => 'password']);
        $vola->forceFill(['activation_open_until' => now()->addDay(), 'active' => false])->save();
        $this->postJson('/login/identifier', ['email' => $vola->email])->assertExactJson(['mode' => 'password']);

        $this->postJson('/login/identifier', ['email' => 'pas-une-adresse'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_choosing_a_password_activates_the_account_signs_in_and_notifies(): void
    {
        Notification::fake();
        $hr = $this->hr();
        $vola = $this->awaiting(sentHandover: true);

        $this->post('/login/premiere-connexion', [
            'email' => 'vola.rabe@cliniquesaintgeorges.mg',
            'password' => self::CHOSEN,
            'password_confirmation' => self::CHOSEN,
        ], ['User-Agent' => 'Navigateur de test'])->assertRedirect('/')->assertSessionHas('status');

        $this->assertAuthenticatedAs($vola);
        $vola->refresh();
        $this->assertTrue(Hash::check(self::CHOSEN, $vola->password));
        $this->assertNotNull($vola->activated_at);
        $this->assertNull($vola->activation_open_until);
        $this->assertNotNull($vola->last_login_at);
        $this->assertFalse($vola->awaitsActivation());

        $audit = AuditLog::query()->where('action', 'user.activate')->sole();
        $this->assertSame('Navigateur de test', $audit->new_values['user_agent']);
        $this->assertArrayHasKey('ip', $audit->new_values);
        $this->assertFalse($audit->new_values['mailbox_synced'], 'aucun accès à l’hébergeur sur ce site');
        $this->assertStringNotContainsString(self::CHOSEN, AuditLog::query()->get()->toJson(), 'jamais dans l’audit');

        Notification::assertSentTo($vola, WelcomeToPlatform::class, fn (WelcomeToPlatform $welcome) => $welcome->mailbox === 'vola.rabe@cliniquesaintgeorges.mg' && ! $welcome->mailboxReady);
        Notification::assertSentTo($vola, AccountActivatedMail::class);
        Notification::assertSentTo($hr, StaffAccessActivated::class, fn (StaffAccessActivated $notice) => $notice->name === 'Vola RABE' && $notice->handoverUuid !== null);

        // Désormais : l'adresse, puis le mot de passe — le choisi.
        auth()->logout();
        $this->postJson('/login/identifier', ['email' => $vola->email])->assertExactJson(['mode' => 'password']);
        $this->post('/login', ['email' => $vola->email, 'password' => self::CHOSEN])->assertRedirect('/');
        $this->assertAuthenticatedAs($vola);
    }

    public function test_the_chosen_password_follows_the_rules_and_is_confirmed(): void
    {
        $vola = $this->awaiting();

        $this->post('/login/premiere-connexion', ['email' => $vola->email, 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertSessionHasErrors('password');
        $this->post('/login/premiere-connexion', ['email' => $vola->email, 'password' => self::CHOSEN, 'password_confirmation' => 'Autre-Choix-2026!'])
            ->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertTrue($vola->fresh()->awaitsActivation(), 'rien n’a changé');
    }

    public function test_only_an_account_awaiting_its_first_login_can_choose_a_password_this_way(): void
    {
        $ordinary = User::factory()->create(['role_id' => $this->roleId('RECEPTION'), 'email' => 'deja.la@cliniquesaintgeorges.mg']);
        $expired = $this->awaiting();
        $expired->forceFill(['activation_open_until' => now()->subMinute()])->save();

        foreach ([$ordinary->email, $expired->email, 'personne@cliniquesaintgeorges.mg'] as $email) {
            $this->post('/login/premiere-connexion', ['email' => $email, 'password' => self::CHOSEN, 'password_confirmation' => self::CHOSEN])
                ->assertSessionHasErrors('email');
        }

        $this->assertGuest();
        $this->assertTrue(Hash::check('password', $ordinary->fresh()->password), 'un compte utilisé ne se reprend jamais par l’adresse seule');
        $this->assertNull($expired->fresh()->activated_at);
    }

    public function test_a_second_activation_of_the_same_account_is_refused(): void
    {
        $vola = $this->awaiting();

        $this->post('/login/premiere-connexion', ['email' => $vola->email, 'password' => self::CHOSEN, 'password_confirmation' => self::CHOSEN])->assertRedirect('/');
        auth()->logout();

        $this->post('/login/premiere-connexion', ['email' => $vola->email, 'password' => 'Pris-Par-Un-Autre-9!', 'password_confirmation' => 'Pris-Par-Un-Autre-9!'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::CHOSEN, $vola->fresh()->password));
    }

    public function test_the_mailbox_receives_the_same_password_when_the_site_reaches_the_host(): void
    {
        Notification::fake();
        config(['rivo.professional_email' => [
            'domain' => 'cliniquesaintgeorges.mg',
            'hosting' => ['url' => self::HOST, 'user' => 'flbe4406', 'token' => 'SECRET-TOKEN', 'quota_mb' => 1024, 'timeout' => 5],
        ]]);
        Http::fake([self::HOST.'/execute/Email/passwd_pop' => Http::response(['status' => 1])]);
        $vola = $this->awaiting();

        $this->post('/login/premiere-connexion', ['email' => $vola->email, 'password' => self::CHOSEN, 'password_confirmation' => self::CHOSEN])->assertRedirect('/');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/passwd_pop')
            && $request['email'] === 'vola.rabe' && $request['domain'] === 'cliniquesaintgeorges.mg' && $request['password'] === self::CHOSEN);
        $this->assertTrue(AuditLog::query()->where('action', 'user.activate')->sole()->new_values['mailbox_synced']);
        Notification::assertSentTo($vola, WelcomeToPlatform::class, fn (WelcomeToPlatform $welcome) => $welcome->mailboxReady);
        $this->assertTrue(session()->has('webmail.own'), 'la messagerie s’ouvrira sans redemander le mot de passe (ADR-200)');
    }

    public function test_a_host_that_refuses_never_blocks_the_first_login(): void
    {
        Notification::fake();
        config(['rivo.professional_email' => [
            'domain' => 'cliniquesaintgeorges.mg',
            'hosting' => ['url' => self::HOST, 'user' => 'flbe4406', 'token' => 'SECRET-TOKEN', 'quota_mb' => 1024, 'timeout' => 5],
        ]]);
        Http::fake([self::HOST.'/*' => Http::response(['status' => 0, 'errors' => ['Mot de passe trop faible.']])]);
        $vola = $this->awaiting();

        $this->post('/login/premiere-connexion', ['email' => $vola->email, 'password' => self::CHOSEN, 'password_confirmation' => self::CHOSEN])->assertRedirect('/');

        $this->assertAuthenticatedAs($vola);
        $audit = AuditLog::query()->where('action', 'user.activate')->sole();
        $this->assertFalse($audit->new_values['mailbox_synced']);
        $this->assertStringContainsString('trop faible', $audit->new_values['mailbox_error']);
        Notification::assertSentTo($vola, WelcomeToPlatform::class, fn (WelcomeToPlatform $welcome) => ! $welcome->mailboxReady && str_contains($welcome->payload()['body'], 'prévenez le RH'));
        $this->assertFalse(session()->has('webmail.own'), 'la boîte n’a pas ce mot de passe : il n’y est pas présenté');
    }

    public function test_a_first_ordinary_login_or_a_reset_link_counts_as_the_first_login(): void
    {
        $legacy = User::factory()->create(['role_id' => $this->roleId('RECEPTION'), 'email' => 'ancien@cliniquesaintgeorges.mg']);
        $this->assertNull($legacy->activated_at);

        $this->post('/login', ['email' => $legacy->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertNotNull($legacy->fresh()->activated_at);
    }

    /* ------------------------------------------------------------------ */

    private function awaiting(bool $sentHandover = false): User
    {
        $doctor = HrReferenceValue::query()->create(['type' => HrReferenceType::JobTitle, 'code' => 'DOCTOR', 'label' => 'Médecin', 'active' => true]);
        $user = User::factory()->create(['role_id' => $this->roleId('MEDICINE'), 'name' => 'Vola RABE', 'email' => 'vola.rabe@cliniquesaintgeorges.mg', 'last_login_at' => null]);
        $user->forceFill(['activated_at' => null, 'activation_open_until' => now()->addDays(14)])->save();

        $employee = Employee::query()->create([
            'employee_number' => 'EMP-0001', 'last_name' => 'RABE', 'first_name' => 'Vola', 'sex' => 'F', 'active' => true,
            'job_title_id' => $doctor->id,
        ]);
        $employee->forceFill(['user_id' => $user->id])->save();
        ProfessionalMailbox::query()->create([
            'employee_id' => $employee->id, 'address' => 'vola.rabe@cliniquesaintgeorges.mg', 'status' => ProfessionalMailboxStatus::Active,
            'requested_at' => now(), 'activated_at' => now(),
        ]);

        if ($sentHandover) {
            $handover = StaffAccessHandover::query()->create(['sent_at' => now(), 'sent_by_name' => 'Super Admin test']);
            StaffAccessHandoverItem::query()->create([
                'staff_access_handover_id' => $handover->id, 'employee_id' => $employee->id, 'user_id' => $user->id,
                'employee_name' => 'Vola RABE', 'login_email' => $user->email, 'role_label' => 'Médecine',
            ]);
        }

        return $user->fresh();
    }

    private function hr(): User
    {
        return User::factory()->create(['role_id' => $this->roleId('ADMINISTRATION')]);
    }

    private function roleId(string $code): int
    {
        return (int) Role::query()->where('code', $code)->value('id');
    }
}
