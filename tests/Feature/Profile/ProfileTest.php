<?php

namespace Tests\Feature\Profile;

use App\Actions\User\ChangeOwnPasswordAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-184 — « Mon profil » : chaque compte lit son identité et ses droits, et
 * change son propre mot de passe — l'ancien exigé, la politique commune, les
 * autres sessions fermées, audité. Rien d'autre ne s'y modifie (ADR-022).
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Nouveau-mot2passe!';

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get('/profil')->assertRedirect('/login');
    }

    public function test_the_profile_shows_the_account_and_its_effective_permissions(): void
    {
        $user = User::factory()->withRole()->create(['name' => 'Rasoa Vola']);

        $this->actingAs($user)->get('/profil')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Profile/Show')
            ->where('account.name', 'Rasoa Vola')
            ->where('account.email', $user->email)
            ->where('account.role', $user->role->name)
            ->has('grantedPermissions', $user->effectivePermissionNames()->count())
            // La prop partagée du menu latéral reste intacte : une liste de noms.
            ->where('permissions', $user->effectivePermissionNames()->values()->all()));
    }

    public function test_the_current_password_is_required_and_checked(): void
    {
        $user = User::factory()->withRole()->create();

        $this->actingAs($user)->put('/profil/mot-de-passe', [
            'current_password' => 'pas-le-bon',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors(['current_password' => 'Le mot de passe actuel est incorrect.']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_common_policy_applies_and_the_password_must_change(): void
    {
        $user = User::factory()->withRole()->create();

        $this->actingAs($user)->put('/profil/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'court',
            'password_confirmation' => 'court',
        ])->assertSessionHasErrors('password');

        $this->actingAs($user)->put('/profil/mot-de-passe', [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_password_is_changed_and_audited_without_logging_it(): void
    {
        $user = User::factory()->withRole()->create();

        $this->actingAs($user)->put('/profil/mot-de-passe', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $this->assertAuthenticatedAs($user);

        $audit = DB::table('audit_logs')->where('action', 'user.password.change')->sole();
        $this->assertStringNotContainsString(self::NEW_PASSWORD, (string) $audit->new_values);
    }

    public function test_the_other_sessions_are_closed_and_the_current_one_kept(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->withRole()->create();
        $other = User::factory()->withRole()->create();

        foreach ([['current', $user], ['phone', $user], ['desk', $user], ['colleague', $other]] as [$id, $owner]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner->id, 'payload' => '', 'last_activity' => time()]);
        }

        $closed = app(ChangeOwnPasswordAction::class)->execute($user, self::NEW_PASSWORD, 'current');

        $this->assertSame(2, $closed);
        $this->assertSame(['colleague', 'current'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
    }

    public function test_the_header_links_to_the_profile(): void
    {
        $header = file_get_contents(resource_path('js/Components/Layout/Header.vue'));

        $this->assertStringContainsString('href="/profil"', $header);
        $this->assertStringNotContainsString('Bientôt', $header);
    }
}
