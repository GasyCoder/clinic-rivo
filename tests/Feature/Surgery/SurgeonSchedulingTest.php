<?php

namespace Tests\Feature\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTeamFunction;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\PlanningShift;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\SurgicalRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProfessionalProfileSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-168 — programmer une intervention : un chirurgien principal et des aides,
 * tous au profil Chirurgien, disponibles au planning RH à l'heure choisie.
 */
class SurgeonSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private const AT = '2026-10-05 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['rivo.site.type' => 'clinic']);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, ProfessionalProfileSeeder::class]);
    }

    private function account(?string $profile = 'SURGEON', array $attributes = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('code', 'SURGERY')->value('id'),
            'professional_profile_id' => $profile ? ProfessionalProfile::query()->where('code', $profile)->value('id') : null,
            ...$attributes,
        ]);
    }

    /** Relie une fiche RH au compte, avec éventuellement un créneau de planning. */
    private function planning(User $user, ?array $shift = null, bool $active = true): Employee
    {
        $employee = Employee::query()->create([
            'employee_number' => 'RH-'.$user->id,
            'last_name' => $user->name,
            'sex' => 'M',
            'active' => $active,
            'user_id' => $user->id,
        ]);

        if ($shift) {
            PlanningShift::query()->create([
                'employee_id' => $employee->id,
                'starts_at' => $shift[0],
                'ends_at' => $shift[1],
                'title' => 'Garde bloc',
            ]);
        }

        return $employee;
    }

    private function surgicalRequest(): SurgicalRequest
    {
        $patient = Patient::query()->create([
            'patient_number' => fake()->unique()->numerify('M-26-####'),
            'first_name' => 'Jean',
            'last_name' => 'Rakoto',
            'birth_date' => '1990-05-12',
            'sex' => 'M',
        ]);
        $episode = Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-01',
            'status' => 'OPEN',
            'administrative_status' => 'ORIENTED',
            'started_at' => now(),
        ]);

        return $episode->surgicalRequests()->create([
            'status' => SurgicalRequestStatus::Pending,
            'procedure_name' => 'Appendicectomie',
        ]);
    }

    /** @return array<int, int> */
    private function aides(SurgicalRequest $request): array
    {
        return $request->teamMembers()
            ->where('function', SurgicalTeamFunction::Surgeon->value)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function test_the_migration_adds_the_three_surgery_profiles_without_qualifying_anyone(): void
    {
        $unqualified = $this->account(null);
        DB::table('professional_profiles')->whereIn('code', ['SURGEON', 'OR_NURSE', 'SURGICAL_PARAMEDICAL'])->delete();

        $migration = require database_path('migrations/2026_10_22_090000_create_surgery_professional_profiles.php');
        $migration->up();
        $migration->up();

        $surgeryRoleId = Role::query()->where('code', 'SURGERY')->value('id');
        $this->assertSame(
            ['OR_NURSE', 'SURGEON', 'SURGICAL_PARAMEDICAL'],
            ProfessionalProfile::query()->where('role_id', $surgeryRoleId)->orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame(0, ProfessionalProfile::query()->where('role_id', $surgeryRoleId)->whereHas('recommendedPermissions')->count());
        $this->assertNull($unqualified->fresh()->professional_profile_id);
    }

    public function test_only_a_surgeon_profile_can_be_the_lead_surgeon(): void
    {
        $scheduler = $this->account();
        $nurse = $this->account('OR_NURSE');
        $request = $this->surgicalRequest();

        $this->actingAs($scheduler)
            ->post("/surgery/{$request->uuid}/schedule", ['surgeon_id' => $nurse->id, 'scheduled_at' => self::AT])
            ->assertSessionHasErrors(['surgeon_id' => "« {$nurse->name} » n’a pas le profil Chirurgien : il ne peut pas être programmé comme chirurgien."]);

        $this->assertSame(SurgicalRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_an_unlinked_surgeon_is_accepted_and_the_aides_join_the_team(): void
    {
        $lead = $this->account();
        $aideOne = $this->account();
        $aideTwo = $this->account();
        $request = $this->surgicalRequest();

        $this->actingAs($lead)
            ->post("/surgery/{$request->uuid}/schedule", [
                'surgeon_id' => $lead->id,
                'assistant_surgeon_ids' => [$aideOne->id, $aideTwo->id],
                'scheduled_at' => self::AT,
            ])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(SurgicalRequestStatus::Scheduled, $request->status);
        $this->assertSame($lead->id, (int) $request->surgeon_id);
        $this->assertSame([$aideOne->id, $aideTwo->id], $this->aides($request));
    }

    public function test_the_planning_decides_who_is_available_at_the_scheduled_hour(): void
    {
        $scheduler = $this->account();
        $onDuty = $this->account();
        $offDuty = $this->account();
        $archived = $this->account();
        $this->planning($onDuty, ['2026-10-05 08:00:00', '2026-10-05 16:00:00']);
        $this->planning($offDuty, ['2026-10-06 08:00:00', '2026-10-06 16:00:00']);
        $this->planning($archived, ['2026-10-05 08:00:00', '2026-10-05 16:00:00'], active: false);
        $request = $this->surgicalRequest();

        $this->actingAs($scheduler)
            ->post("/surgery/{$request->uuid}/schedule", [
                'surgeon_id' => $offDuty->id,
                'assistant_surgeon_ids' => [$archived->id],
                'scheduled_at' => self::AT,
            ])
            ->assertSessionHasErrors([
                'surgeon_id' => "« {$offDuty->name} » : absent du planning RH le 05/10/2026 à 09:00.",
                'assistant_surgeon_ids.0' => "« {$archived->name} » : fiche RH inactive ou archivée.",
            ]);
        $this->assertSame(SurgicalRequestStatus::Pending, $request->fresh()->status);

        $this->actingAs($scheduler)
            ->post("/surgery/{$request->uuid}/schedule", ['surgeon_id' => $onDuty->id, 'scheduled_at' => self::AT])
            ->assertSessionHasNoErrors();
        $this->assertSame($onDuty->id, (int) $request->fresh()->surgeon_id);
    }

    public function test_correcting_the_schedule_syncs_the_aides_and_never_duplicates_the_lead(): void
    {
        [$lead, $aideOne, $aideTwo] = [$this->account(), $this->account(), $this->account()];
        $request = $this->surgicalRequest();

        $this->actingAs($lead)->post("/surgery/{$request->uuid}/schedule", [
            'surgeon_id' => $lead->id,
            'assistant_surgeon_ids' => [$aideOne->id, $aideTwo->id],
            'scheduled_at' => self::AT,
        ])->assertSessionHasNoErrors();

        // L'aide 1 devient principal, l'ancien principal devient aide, l'aide 2 est retiré.
        $this->actingAs($lead)->post("/surgery/{$request->uuid}/schedule", [
            'surgeon_id' => $aideOne->id,
            'assistant_surgeon_ids' => [$lead->id],
            'scheduled_at' => self::AT,
        ])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame($aideOne->id, (int) $request->surgeon_id);
        $this->assertSame([$lead->id], $this->aides($request));
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete', 'module' => 'surgery']);
    }

    public function test_omitting_the_aides_leaves_the_team_untouched_and_an_empty_list_clears_it(): void
    {
        [$lead, $aide] = [$this->account(), $this->account()];
        $request = $this->surgicalRequest();

        $this->actingAs($lead)->post("/surgery/{$request->uuid}/schedule", [
            'surgeon_id' => $lead->id, 'assistant_surgeon_ids' => [$aide->id], 'scheduled_at' => self::AT,
        ])->assertSessionHasNoErrors();

        $this->actingAs($lead)->post("/surgery/{$request->uuid}/schedule", [
            'surgeon_id' => $lead->id, 'scheduled_at' => '2026-10-05 10:00:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame([$aide->id], $this->aides($request->fresh()));

        $this->actingAs($lead)->postJson("/surgery/{$request->uuid}/schedule", [
            'surgeon_id' => $lead->id, 'assistant_surgeon_ids' => [], 'scheduled_at' => '2026-10-05 10:00:00',
        ]);
        $this->assertSame([], $this->aides($request->fresh()));
    }

    public function test_the_lead_cannot_also_be_an_aide_nor_an_aide_twice(): void
    {
        [$lead, $aide] = [$this->account(), $this->account()];
        $request = $this->surgicalRequest();

        $this->actingAs($lead)->post("/surgery/{$request->uuid}/schedule", [
            'surgeon_id' => $lead->id, 'assistant_surgeon_ids' => [$lead->id], 'scheduled_at' => self::AT,
        ])->assertSessionHasErrors('assistant_surgeon_ids.0');

        $this->actingAs($lead)->post("/surgery/{$request->uuid}/schedule", [
            'surgeon_id' => $lead->id, 'assistant_surgeon_ids' => [$aide->id, $aide->id], 'scheduled_at' => self::AT,
        ])->assertSessionHasErrors('assistant_surgeon_ids.0');

        $this->assertSame(SurgicalRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_the_surgeons_endpoint_reports_planning_states_and_who_is_me(): void
    {
        $me = $this->account();
        $onDuty = $this->account();
        $offDuty = $this->account();
        $nurse = $this->account('OR_NURSE');
        $inactive = $this->account('SURGEON', ['active' => false, 'deactivated_at' => now()]);
        $this->planning($onDuty, ['2026-10-05 08:00:00', '2026-10-05 16:00:00']);
        $this->planning($offDuty);
        $request = $this->surgicalRequest();

        $response = $this->actingAs($me)
            ->getJson("/surgery/{$request->uuid}/surgeons?at=".urlencode('2026-10-05T09:00'))
            ->assertOk();

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$me->id, $offDuty->id, $onDuty->id], $rows->keys()->all());
        $this->assertFalse($rows->has($nurse->id));
        $this->assertFalse($rows->has($inactive->id));
        $this->assertTrue($rows[$me->id]['is_me']);
        $this->assertSame('UNLINKED', $rows[$me->id]['state']);
        $this->assertTrue($rows[$me->id]['selectable']);
        $this->assertSame('AVAILABLE', $rows[$onDuty->id]['state']);
        $this->assertSame('au planning de 08:00 à 16:00', $rows[$onDuty->id]['label']);
        $this->assertSame('OFF_PLANNING', $rows[$offDuty->id]['state']);
        $this->assertFalse($rows[$offDuty->id]['selectable']);

        $nurseAccount = User::factory()->create(['role_id' => Role::query()->where('code', 'NURSE')->value('id')]);
        $this->actingAs($nurseAccount)
            ->getJson("/surgery/{$request->uuid}/surgeons?at=".urlencode('2026-10-05T09:00'))
            ->assertForbidden();
    }

    public function test_the_free_team_form_no_longer_adds_a_surgeon(): void
    {
        $actor = $this->account();
        $request = $this->surgicalRequest();

        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/team", ['user_id' => $this->account()->id, 'function' => 'SURGEON'])
            ->assertSessionHasErrors(['function' => 'Les chirurgiens se choisissent avec la programmation de l’intervention (profil Chirurgien, planning RH).']);

        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/team", ['user_id' => $this->account('OR_NURSE')->id, 'function' => 'OR_NURSE'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $request->teamMembers()->count());
    }

    public function test_a_team_function_only_accepts_the_matching_professional_profile(): void
    {
        $actor = $this->account();
        $request = $this->surgicalRequest();
        $anesthetist = User::factory()->create([
            'role_id' => Role::query()->where('code', 'NURSE')->value('id'),
            'professional_profile_id' => ProfessionalProfile::query()->where('code', 'ANESTHETIST')->value('id'),
        ]);
        $orNurse = $this->account('OR_NURSE');
        $receptionist = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);

        // Un compte sans le profil de la fonction est refusé, même envoyé à la main.
        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/team", ['user_id' => $receptionist->id, 'function' => 'ANESTHETIST'])
            ->assertSessionHasErrors(['user_id' => "« {$receptionist->name} » n’a pas le profil métier Anesthésiste : il ne peut pas tenir cette fonction au bloc."]);
        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/team", ['user_id' => $orNurse->id, 'function' => 'ANESTHETIST'])
            ->assertSessionHasErrors('user_id');

        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/team", ['user_id' => $anesthetist->id, 'function' => 'ANESTHETIST'])
            ->assertSessionHasNoErrors();
        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/team", ['user_id' => $orNurse->id, 'function' => 'OR_NURSE'])
            ->assertSessionHasNoErrors();

        // Une même personne n'est pas inscrite deux fois à la même fonction.
        $this->actingAs($actor)
            ->post("/surgery/{$request->uuid}/team", ['user_id' => $anesthetist->id, 'function' => 'ANESTHETIST'])
            ->assertSessionHasErrors(['user_id' => "« {$anesthetist->name} » est déjà dans l’équipe comme Anesthésiste."]);

        $this->assertSame(2, $request->teamMembers()->count());
    }

    public function test_the_page_tells_the_screen_which_profile_holds_each_function(): void
    {
        $actor = $this->account();
        $request = $this->surgicalRequest();

        $this->actingAs($actor)
            ->get("/surgery/{$request->uuid}")
            ->assertInertia(fn ($page) => $page
                ->component('Surgery/Show')
                ->where('teamFunctions', fn ($functions) => collect($functions)->pluck('profile', 'value')->all() === [
                    'SURGEON' => 'SURGEON',
                    'ANESTHETIST' => 'ANESTHETIST',
                    'OR_NURSE' => 'OR_NURSE',
                    'PARAMEDICAL' => 'SURGICAL_PARAMEDICAL',
                ]));
    }
}
