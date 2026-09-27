<?php

namespace Tests\Feature\Administration;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\ProfessionalMailbox;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

/**
 * ADR-190 — côté site : le RH demande l'adresse email professionnelle d'un
 * employé ; le portail, après l'avoir créée chez l'hébergeur, l'active ici.
 */
class ProfessionalMailboxTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR_UUID = '6d3f4a8e-1c2b-4d5e-9f60-7a8b9c0d1e2f';

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.site.name' => 'Ambondromamy',
            'rivo.site_api.token' => 'clinic-test-token',
            'rivo.professional_email.domain' => 'cbdc.mg',
        ]);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->hr = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_the_employee_record_proposes_firstname_lastname_without_accents(): void
    {
        $employee = $this->employee('RH-001', ['first_name' => 'Zéphyr', 'last_name' => 'Andrianina']);

        $this->actingAs($this->hr)->get("/administration/employees/{$employee->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('professionalEmail.domain', 'cbdc.mg')
                ->where('professionalEmail.suggestion', 'zephyr.andrianina')
                ->where('professionalEmail.can_request', true)
                ->where('professionalEmail.current', null));
    }

    public function test_a_homonym_gets_a_numbered_suggestion(): void
    {
        $this->employee('RH-001', ['email' => 'hery.rabe@cbdc.mg']);
        $second = $this->employee('RH-002');

        $this->actingAs($this->hr)->get("/administration/employees/{$second->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('professionalEmail.suggestion', 'hery.rabe2'));
    }

    public function test_hr_requests_an_address_and_nothing_is_created_at_the_host(): void
    {
        $employee = $this->employee('RH-001');

        $this->actingAs($this->hr)
            ->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'Hery.Rabe', 'note' => 'Arrivé lundi'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $mailbox = ProfessionalMailbox::query()->sole();
        $this->assertSame('hery.rabe@cbdc.mg', $mailbox->address);
        $this->assertSame(ProfessionalMailboxStatus::Requested, $mailbox->status);
        $this->assertSame($this->hr->id, $mailbox->requested_by);
        $this->assertSame('Arrivé lundi', $mailbox->request_note);
        $this->assertSame('rh-001@clinique.test', $employee->fresh()->email, 'la fiche ne change qu’à la création réelle');
    }

    public function test_a_malformed_address_or_a_second_open_request_is_refused(): void
    {
        $employee = $this->employee('RH-001');

        $this->actingAs($this->hr)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'héry rabe'])
            ->assertSessionHasErrors('local_part');
        $this->actingAs($this->hr)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => '.hery'])
            ->assertSessionHasErrors('local_part');

        $this->actingAs($this->hr)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'hery.rabe'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'hery.r'])
            ->assertSessionHasErrors('local_part');

        $other = $this->employee('RH-002');
        $this->actingAs($this->hr)->post("/administration/employees/{$other->uuid}/professional-mailbox", ['local_part' => 'hery.rabe'])
            ->assertSessionHasErrors('local_part');

        $this->assertSame(1, ProfessionalMailbox::query()->count());
    }

    public function test_no_request_for_a_departed_employee_or_without_a_domain(): void
    {
        $departed = $this->employee('RH-001', ['active' => false]);
        $this->actingAs($this->hr)->post("/administration/employees/{$departed->uuid}/professional-mailbox", ['local_part' => 'hery.rabe'])
            ->assertSessionHasErrors('local_part');

        config(['rivo.professional_email.domain' => '']);
        $employee = $this->employee('RH-002');
        $this->actingAs($this->hr)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'hery.rabe'])
            ->assertSessionHasErrors('local_part');

        $this->assertSame(0, ProfessionalMailbox::query()->count());
    }

    public function test_only_the_right_permission_requests(): void
    {
        $employee = $this->employee('RH-001');
        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);

        $this->actingAs($reception)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'hery.rabe'])
            ->assertForbidden();
    }

    public function test_hr_cancels_its_pending_request_which_frees_the_address(): void
    {
        $employee = $this->employee('RH-001');
        $this->actingAs($this->hr)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'hery.rabe']);
        $mailbox = ProfessionalMailbox::query()->sole();

        $this->actingAs($this->hr)->post("/administration/professional-mailboxes/{$mailbox->uuid}/cancel")->assertSessionHasNoErrors();

        $this->assertSame(ProfessionalMailboxStatus::Cancelled, $mailbox->fresh()->status);
        $this->assertNull($mailbox->fresh()->active_key);
        $this->actingAs($this->hr)->post("/administration/employees/{$employee->uuid}/professional-mailbox", ['local_part' => 'hery.rabe'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_portal_activates_the_address_which_becomes_the_employee_email(): void
    {
        $mailbox = $this->requested();

        $this->withHeaders($this->headers(['professional_emails.view', 'professional_emails.create']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/activate", ['address' => 'hery.rabe2@cbdc.mg'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.address', 'hery.rabe2@cbdc.mg')
            ->assertJsonPath('data.decided_by', 'Direction centrale');

        $this->assertSame('hery.rabe2@cbdc.mg', $mailbox->employee->fresh()->email);
        $audit = AuditLog::query()->where('entity_type', $mailbox->getMorphClass())->where('entity_id', $mailbox->id)->latest('id')->firstOrFail();
        $this->assertSame(self::ACTOR_UUID, $audit->external_actor_uuid);

        // Rejouée à l'identique (confirmation perdue puis renvoyée) : rien ne change, rien ne casse.
        $this->withHeaders($this->headers(['professional_emails.create']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/activate", ['address' => 'hery.rabe2@cbdc.mg'])
            ->assertOk();
    }

    public function test_the_portal_cannot_activate_outside_the_domain_or_without_the_right(): void
    {
        $mailbox = $this->requested();

        $this->withHeaders($this->headers(['professional_emails.create']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/activate", ['address' => 'hery@gmail.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('address');

        $this->withHeaders($this->headers(['professional_emails.view']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/activate", ['address' => 'hery.rabe@cbdc.mg'])
            ->assertForbidden();

        $this->assertSame(ProfessionalMailboxStatus::Requested, $mailbox->fresh()->status);
    }

    public function test_reject_suspend_and_reactivate_follow_their_transitions(): void
    {
        $rejected = $this->requested();
        $this->withHeaders($this->headers(['professional_emails.reject']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$rejected->uuid}/reject", ['reason' => 'Adresse générique à préférer'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $mailbox = $this->requested('RH-002');
        $this->withHeaders($this->headers(['professional_emails.deactivate']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/suspend", ['reason' => 'Départ de la clinique'])
            ->assertUnprocessable()->assertJsonValidationErrors('mailbox');

        $this->withHeaders($this->headers(['professional_emails.create']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/activate", ['address' => $mailbox->address])->assertOk();

        $mailbox->employee->forceFill(['active' => false])->save();
        $this->withHeaders($this->headers(['professional_emails.view']))
            ->getJson('/api/v1/super-admin/professional-mailboxes')
            ->assertOk()
            ->assertJsonPath('meta.summary.to_suspend', 1)
            ->assertJsonPath('meta.domain', 'cbdc.mg');

        $this->withHeaders($this->headers(['professional_emails.deactivate']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/suspend", ['reason' => 'Départ de la clinique'])
            ->assertOk()->assertJsonPath('data.status', 'SUSPENDED')->assertJsonPath('data.suspended_by', 'Direction centrale');

        $this->withHeaders($this->headers(['professional_emails.activate']))
            ->postJson("/api/v1/super-admin/professional-mailboxes/{$mailbox->uuid}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.suspended_at', null);
    }

    /** « Nouvelle adresse » : le portail enregistre la demande lui-même, et voit qui n'a pas encore d'adresse. */
    public function test_the_portal_requests_directly_and_lists_employees_without_an_address(): void
    {
        $free = $this->employee('RH-001', ['first_name' => 'Zéphyr']);
        $taken = $this->requested('RH-002');
        $this->employee('RH-003', ['active' => false]);

        $this->withHeaders($this->headers(['professional_emails.view', 'professional_emails.request']))
            ->getJson('/api/v1/super-admin/professional-mailboxes')
            ->assertOk()
            ->assertJsonCount(1, 'meta.candidates')
            ->assertJsonPath('meta.candidates.0.uuid', $free->uuid)
            ->assertJsonPath('meta.candidates.0.suggestion', 'zephyr.rabe');

        $this->withHeaders($this->headers(['professional_emails.view']))
            ->getJson('/api/v1/super-admin/professional-mailboxes')
            ->assertJsonCount(0, 'meta.candidates');

        $this->withHeaders($this->headers(['professional_emails.request']))
            ->postJson('/api/v1/super-admin/professional-mailboxes', ['employee_uuid' => $free->uuid, 'local_part' => 'zephyr.rabe'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'REQUESTED')
            ->assertJsonPath('data.requested_by', 'Direction centrale');

        $this->withHeaders($this->headers(['professional_emails.request']))
            ->postJson('/api/v1/super-admin/professional-mailboxes', ['employee_uuid' => $taken->employee->uuid, 'local_part' => 'autre'])
            ->assertUnprocessable()->assertJsonValidationErrors('local_part');

        $this->withHeaders($this->headers(['professional_emails.view']))
            ->postJson('/api/v1/super-admin/professional-mailboxes', ['employee_uuid' => $free->uuid, 'local_part' => 'x'])
            ->assertForbidden();
    }

    public function test_hr_sees_the_site_page_with_its_addresses_and_candidates(): void
    {
        $this->requested('RH-002');
        $this->employee('RH-001');

        $this->actingAs($this->hr)->get('/administration/professional-emails')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/ProfessionalEmails/Index')
                ->where('sites.0.site.code', 'A')
                ->has('sites.0.data', 1)
                ->has('sites.0.meta.candidates', 1)
                ->where('hosting.configured', false));
    }

    /** Amendement du 2026-09-25 : le Super Admin accorde le droit, le RH crée depuis son site. */
    public function test_hr_granted_the_right_creates_and_suspends_from_the_site(): void
    {
        config(['rivo.professional_email.hosting' => ['url' => 'https://abyssin.test:2083', 'user' => 'flbe4406', 'password' => 'secret-cpanel', 'token' => '', 'quota_mb' => 1024, 'timeout' => 5]]);
        Http::fake([
            'https://abyssin.test:2083/login/*' => Http::response(
                ['status' => 1, 'security_token' => '/cpsess0123456789'], 200, ['Set-Cookie' => 'cpsession=flbe4406%3aSESSION; path=/'],
            ),
            'https://abyssin.test:2083/cpsess0123456789/execute/Email/add_pop' => Http::response(['status' => 1]),
            'https://abyssin.test:2083/cpsess0123456789/execute/Email/suspend_login' => Http::response(['status' => 1]),
            'https://abyssin.test:2083/cpsess0123456789/logout/' => Http::response(''),
        ]);
        $employee = $this->employee('RH-001');

        $this->actingAs($this->hr)->postJson('/administration/professional-emails/direct', ['employee_uuid' => $employee->uuid, 'local_part' => 'hery.rabe'])
            ->assertForbidden();

        $this->grant($this->hr, ['professional_emails.create', 'professional_emails.deactivate']);

        $password = $this->actingAs($this->hr->fresh())
            ->postJson('/administration/professional-emails/direct', ['employee_uuid' => $employee->uuid, 'local_part' => 'hery.rabe'])
            ->assertOk()
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('address', 'hery.rabe@cbdc.mg')
            ->json('password');

        $this->assertNotEmpty($password);
        $mailbox = ProfessionalMailbox::query()->sole();
        $this->assertSame(ProfessionalMailboxStatus::Active, $mailbox->status);
        $this->assertSame('hery.rabe@cbdc.mg', $employee->fresh()->email);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/login/?login_only=1') && $request['pass'] === 'secret-cpanel');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/cpsess0123456789/execute/Email/add_pop') && $request->hasHeader('Cookie', 'cpsession=flbe4406%3aSESSION'));

        $this->actingAs($this->hr->fresh())->post("/administration/professional-emails/{$mailbox->uuid}/suspend", ['reason' => 'Départ de la clinique'])
            ->assertSessionHasNoErrors();
        $this->assertSame(ProfessionalMailboxStatus::Suspended, $mailbox->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'suspend_login'));
    }

    public function test_without_hosting_access_on_the_site_nothing_is_attempted(): void
    {
        Http::fake();
        $this->grant($this->hr, ['professional_emails.create']);
        $employee = $this->employee('RH-001');

        $this->actingAs($this->hr->fresh())->postJson('/administration/professional-emails/direct', ['employee_uuid' => $employee->uuid, 'local_part' => 'hery.rabe'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'L’accès à l’hébergeur n’est pas configuré sur ce serveur (RIVO_MAIL_HOSTING_URL, _USER, et _TOKEN ou _PASSWORD).');

        Http::assertNothingSent();
        $this->assertSame(0, ProfessionalMailbox::query()->count(), 'rien n’est enregistré tant que la création est impossible');
    }

    /** @param array<int, string> $permissions */
    private function grant(User $user, array $permissions): void
    {
        $user->permissions()->attach(
            Permission::query()->whereIn('name', $permissions)->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['effect' => 'allow']])->all(),
        );
    }

    public function test_an_address_is_never_deleted(): void
    {
        $this->expectException(LogicException::class);

        $this->requested()->delete();
    }

    private function requested(string $number = 'RH-001'): ProfessionalMailbox
    {
        $employee = $this->employee($number, ['first_name' => 'Hery'.$number]);

        return ProfessionalMailbox::query()->create([
            'employee_id' => $employee->id,
            'address' => 'hery.'.strtolower(str_replace('-', '', $number)).'@cbdc.mg',
            'status' => ProfessionalMailboxStatus::Requested,
            'requested_at' => now(),
            'requested_by' => $this->hr->id,
        ]);
    }

    private function employee(string $number, array $attributes = []): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'last_name' => 'RABE',
            'first_name' => 'Hery',
            'sex' => 'M',
            'email' => strtolower($number).'@clinique.test',
            'active' => true,
            ...$attributes,
        ]);
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
