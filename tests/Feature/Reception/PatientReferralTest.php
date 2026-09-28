<?php

namespace Tests\Feature\Reception;

use App\Enums\PartnerCategory;
use App\Enums\ReferralSource;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\PartnerOrganization;
use App\Models\Patient;
use App\Models\PatientReferral;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-212 — qui a recommandé la clinique à un nouveau patient : noté à la
 * création de son dossier, jamais après coup ; la personne notée reçoit un
 * cadeau, marqué remis une seule fois.
 */
class PatientReferralTest extends TestCase
{
    use RefreshDatabase;

    private const RECEPTION = [
        'episodes.create', 'patients.create', 'patients.view', 'employees.patient_lookup',
        'patient_staff_links.create', 'partner_organizations.view',
        'patient_referrals.view', 'patient_referrals.create', 'patient_referrals.gift',
    ];

    public function test_a_staff_member_who_recommended_the_clinic_is_recorded_with_the_new_patient(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $employee = $this->employee('EMP-R1', 'Rakoto', 'Jean', '0341112233');

        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient([
            'referral' => ['source' => 'EMPLOYEE', 'employee_uuid' => $employee->uuid],
        ]))->assertCreated();

        $referral = PatientReferral::query()->sole();
        $this->assertSame(ReferralSource::Employee, $referral->source);
        $this->assertSame($employee->id, $referral->employee_id);
        $this->assertSame('Rakoto Jean', $referral->referrer_name, 'le nom est figé ce jour-là');
        $this->assertSame('0341112233', $referral->referrer_phone);
        $this->assertSame(Patient::query()->sole()->id, $referral->patient_id);
        $this->assertSame(Episode::query()->sole()->id, $referral->episode_id);
        $this->assertSame($actor->id, $referral->recorded_by);
        $this->assertNull($referral->gift_given_at);
    }

    public function test_another_person_is_noted_by_name_and_a_partner_by_its_file(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $partner = $this->partner('ISPSG');

        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient([
            'referral' => ['source' => 'OTHER', 'name' => '  Voisine   Hery ', 'phone' => ''],
        ]))->assertCreated();
        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient([
            'last_name' => 'Randria',
            'referral' => ['source' => 'PARTNER', 'partner_uuid' => $partner->uuid],
        ]))->assertCreated();

        $other = PatientReferral::query()->where('source', 'OTHER')->sole();
        $this->assertSame('Voisine Hery', $other->referrer_name);
        $this->assertNull($other->referrer_phone);
        $this->assertSame($partner->id, PatientReferral::query()->where('source', 'PARTNER')->sole()->partner_organization_id);

        // Une autre personne sans nom : refusé, et rien n'est créé.
        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient([
            'last_name' => 'Soa',
            'referral' => ['source' => 'OTHER', 'name' => ''],
        ]))->assertUnprocessable()->assertJsonValidationErrors('referral.name');
        $this->assertSame(2, Patient::query()->count());
    }

    public function test_a_referral_is_only_recorded_when_the_patient_record_is_created(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $employee = $this->employee('EMP-R2', 'Rabe', 'Lova');
        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient())->assertCreated();
        $patient = Patient::query()->sole();

        // Un dossier existant : la recommandation se notait à sa première venue.
        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_uuid' => $patient->uuid,
            'referral' => ['source' => 'EMPLOYEE', 'employee_uuid' => $employee->uuid],
        ])->assertUnprocessable()->assertJsonValidationErrors('referral');

        // Un membre du personnel qui vient se faire soigner n'est pas « recommandé ».
        $this->actingAs($actor)->postJson('/reception/patients', [
            'patient_type' => 'STAFF',
            'employee_uuid' => $employee->uuid,
            'referral' => ['source' => 'OTHER', 'name' => 'Quelqu’un'],
        ])->assertUnprocessable()->assertJsonValidationErrors('referral');

        $this->assertSame(0, PatientReferral::query()->count());
        $this->assertSame(1, Episode::query()->count());
    }

    public function test_a_departed_employee_cannot_be_the_referrer(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $departed = $this->employee('EMP-R3', 'Parti', 'Hanta');
        $departed->forceFill(['active' => false])->save();

        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient([
            'referral' => ['source' => 'EMPLOYEE', 'employee_uuid' => $departed->uuid],
        ]))->assertUnprocessable()->assertJsonValidationErrors('referral.employee_uuid');
        // Toute l'arrivée est refusée : aucun dossier à moitié créé.
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_recording_a_referral_needs_its_own_permission(): void
    {
        $actor = $this->receptionist(array_values(array_diff(self::RECEPTION, ['patient_referrals.create'])));

        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient([
            'referral' => ['source' => 'OTHER', 'name' => 'Hery'],
        ]))->assertForbidden();
        $this->actingAs($actor)->getJson('/reception/referrers?q=Ra')->assertForbidden();

        // Sans recommandation, l'accueil se fait comme avant.
        $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient())->assertCreated();
    }

    public function test_the_referrer_is_found_among_staff_in_post_and_active_partners_only(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        $this->employee('EMP-R4', 'Rakotomalala', 'Jean');
        $this->employee('EMP-R5', 'Rakotomalala', 'Paul')->forceFill(['active' => false])->save();
        $this->partner('Rakoto Pharma');
        $this->partner('Rakoto ancien')->forceFill(['active' => false])->save();

        $results = $this->actingAs($actor)->getJson('/reception/referrers?q='.urlencode('rakoto jean'))
            ->assertOk()
            ->json('data');

        $this->assertSame([['EMPLOYEE', 'Rakotomalala Jean']], array_map(fn ($row) => [$row['source'], $row['name']], $results));
        $this->assertArrayNotHasKey('birth_date', $results[0]);

        $names = collect($this->actingAs($actor)->getJson('/reception/referrers?q=Rakoto')->json('data'))->pluck('name')->all();
        $this->assertContains('Rakoto Pharma', $names);
        $this->assertNotContains('Rakoto ancien', $names);
        $this->assertNotContains('Rakotomalala Paul', $names);

        // « % » se cherche tel quel : il ne ramène pas tout.
        $this->actingAs($actor)->getJson('/reception/referrers?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_gift_is_marked_given_once_and_the_list_counts_what_is_left(): void
    {
        $actor = $this->receptionist(self::RECEPTION);
        foreach (['Hery', 'Lova'] as $index => $name) {
            $this->actingAs($actor)->postJson('/reception/patients', $this->newPatient([
                'last_name' => "Patient{$index}",
                'referral' => ['source' => 'OTHER', 'name' => $name],
            ]))->assertCreated();
        }
        $hery = PatientReferral::query()->where('referrer_name', 'Hery')->sole();

        $this->actingAs($actor)->post("/reception/recommandations/{$hery->uuid}/cadeau", ['note' => 'Bon d’achat'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $hery->refresh();
        $this->assertNotNull($hery->gift_given_at);
        $this->assertSame($actor->id, $hery->gift_given_by);
        $this->assertSame('Bon d’achat', $hery->gift_note);

        // Une seconde fois : refusé, la première trace reste.
        $this->actingAs($actor)->post("/reception/recommandations/{$hery->uuid}/cadeau")->assertSessionHasErrors('gift');

        $this->actingAs($actor)->get('/reception/recommandations?cadeau=a-remettre')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reception/Referrals/Index')
                ->where('counts.tous', 2)
                ->where('counts.a-remettre', 1)
                ->where('counts.remis', 1)
                ->has('referrals.data', 1)
                ->where('referrals.data.0.referrer_name', 'Lova')
                // Le mois en cours borne la navigation « mois suivant » de l'écran.
                ->where('currentMonth', now()->format('Y-m')));

        $viewer = $this->receptionist(['patient_referrals.view'], 'VIEWER');
        $this->actingAs($viewer)->post("/reception/recommandations/{$hery->uuid}/cadeau")->assertForbidden();
        // Sans patients.view, le lien vers le dossier n'est pas servi.
        $this->actingAs($viewer)->get('/reception/recommandations')
            ->assertInertia(fn ($page) => $page->where('referrals.data.0.patient.uuid', null));
    }

    /** @param array<string, mixed> $overrides */
    private function newPatient(array $overrides = []): array
    {
        return [
            'patient_type' => 'STANDARD',
            'last_name' => 'Rasoa',
            'first_name' => 'Fara',
            'birth_date' => '1990-05-12',
            'sex' => 'F',
            'confirm_duplicate' => true,
            ...$overrides,
        ];
    }

    private function receptionist(array $permissions, string $code = 'RECEPTION'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function employee(string $number, string $lastName, string $firstName, ?string $phone = null): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => 'M',
            'birth_date' => '1985-01-01',
            'phone' => $phone,
            'active' => true,
        ]);
    }

    private function partner(string $name): PartnerOrganization
    {
        return PartnerOrganization::query()->create([
            'category' => PartnerCategory::Other,
            'name' => $name,
            'active' => true,
        ]);
    }
}
