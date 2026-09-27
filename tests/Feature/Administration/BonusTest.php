<?php

namespace Tests\Feature\Administration;

use App\Enums\BonusAwardStatus;
use App\Enums\BonusMeasure;
use App\Enums\ConsultationStatus;
use App\Enums\ReferralSource;
use App\Models\BonusAward;
use App\Models\BonusCategory;
use App\Models\Consultation;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\PatientReferral;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Bonus\BonusMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-212 — les bonus du personnel : une catégorie compte une mesure par mois,
 * qui atteint son seuil reçoit son montant une fois validé par les RH, puis
 * marqué versé (hors RIVO). Rien n'est calculé en paie (ADR-066, ADR-206).
 */
class BonusTest extends TestCase
{
    use RefreshDatabase;

    private const HR = [
        'bonus_categories.view', 'bonus_categories.create', 'bonus_categories.update',
        'bonus_categories.archive', 'bonus_categories.restore',
        'bonus_awards.view', 'bonus_awards.validate', 'bonus_awards.pay', 'bonus_awards.cancel',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hr_creates_a_category_with_its_measure_threshold_amount_and_staff(): void
    {
        $hr = $this->user(self::HR);
        $surgeon = $this->employee('EMP-B1', 'Rakoto', 'Jean');
        $departed = $this->employee('EMP-B2', 'Parti', 'Hanta');
        $departed->forceFill(['active' => false])->save();

        $this->actingAs($hr)->post('/administration/bonus/categories', [
            'name' => 'Bonus chirurgien',
            'measure' => BonusMeasure::Surgeries->value,
            'threshold' => 20,
            'amount' => '20 000',
            'employee_uuids' => [$surgeon->uuid],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $category = BonusCategory::query()->sole();
        $this->assertSame(BonusMeasure::Surgeries, $category->measure);
        $this->assertSame(20, $category->threshold);
        $this->assertSame('20000.00', (string) $category->amount, '« 20 000 » se lit 20000');
        $this->assertSame([$surgeon->id], $category->employees()->pluck('employees.id')->all());

        // Un membre qui n'est plus en poste ne se choisit pas.
        $this->actingAs($hr)->put("/administration/bonus/categories/{$category->uuid}", [
            'name' => 'Bonus chirurgien',
            'measure' => BonusMeasure::Surgeries->value,
            'threshold' => 20,
            'amount' => 20000,
            'employee_uuids' => [$surgeon->uuid, $departed->uuid],
        ])->assertSessionHasErrors('employee_uuids');

        // Deux catégories ne portent jamais le même nom, casse et accents compris.
        $this->actingAs($hr)->post('/administration/bonus/categories', [
            'name' => 'BONUS CHIRURGIEN',
            'measure' => BonusMeasure::Consultations->value,
            'threshold' => 5,
            'amount' => 5000,
        ])->assertSessionHasErrors('name');

        // Un seuil nul ou un montant nul n'est pas un bonus.
        $this->actingAs($hr)->post('/administration/bonus/categories', [
            'name' => 'Bonus vide',
            'measure' => BonusMeasure::Consultations->value,
            'threshold' => 0,
            'amount' => 0,
        ])->assertSessionHasErrors(['threshold', 'amount']);
    }

    public function test_a_member_who_left_stays_in_the_category_when_it_is_edited(): void
    {
        $hr = $this->user(self::HR);
        $stays = $this->employee('EMP-B3', 'Reste', 'Lova');
        $leaves = $this->employee('EMP-B4', 'Parti', 'Soa');
        $category = $this->category(BonusMeasure::Consultations, 2, [$stays, $leaves]);
        $leaves->forceFill(['active' => false])->save();
        $leaves->delete();

        $this->actingAs($hr)->put("/administration/bonus/categories/{$category->uuid}", [
            'name' => $category->name,
            'measure' => $category->measure->value,
            'threshold' => 3,
            'amount' => 15000,
            'employee_uuids' => [$stays->uuid],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$stays->id, $leaves->id], $category->employees()->withTrashed()->pluck('employees.id')->all());
    }

    public function test_referred_patients_count_distinct_patients_in_the_month(): void
    {
        $recruiter = $this->employee('EMP-B5', 'Rabe', 'Tiana');
        $first = $this->patient('A-26-0101');
        $second = $this->patient('A-26-0102');
        $this->referral($first, $recruiter, '2026-09-03');
        $this->referral($second, $recruiter, '2026-09-18');
        // Le mois d'avant ne compte pas pour septembre.
        $this->referral($this->patient('A-26-0103'), $recruiter, '2026-08-30');

        $counted = app(BonusMeter::class)->patients(BonusMeasure::ReferredPatients, collect([$recruiter]), Carbon::parse('2026-09-01'));

        $this->assertEqualsCanonicalizing(['A-26-0101', 'A-26-0102'], array_column($counted[$recruiter->id], 'patient_number'));
    }

    public function test_patients_treated_are_read_on_the_linked_account_once_per_patient(): void
    {
        $doctorAccount = User::factory()->create();
        $doctor = $this->employee('EMP-B6', 'Randria', 'Paul', $doctorAccount);
        $withoutAccount = $this->employee('EMP-B7', 'Sans', 'Compte');
        $patient = $this->patient('A-26-0201');

        // Deux consultations terminées du même patient : il compte une fois.
        $this->consultation($patient, $doctorAccount, '2026-09-05 09:00');
        $this->consultation($patient, $doctorAccount, '2026-09-12 09:00');
        $this->consultation($this->patient('A-26-0202'), $doctorAccount, '2026-09-13 09:00');
        // Une consultation encore ouverte ne compte pas.
        $this->consultation($this->patient('A-26-0203'), $doctorAccount, null);

        $counted = app(BonusMeter::class)->patients(BonusMeasure::Consultations, collect([$doctor, $withoutAccount]), Carbon::parse('2026-09-01'));

        $this->assertEqualsCanonicalizing(['A-26-0201', 'A-26-0202'], array_column($counted[$doctor->id], 'patient_number'));
        $this->assertSame([], $counted[$withoutAccount->id], 'sans compte relié, rien n’est compté');
    }

    public function test_every_measure_reads_columns_that_exist(): void
    {
        $columns = [
            'patient_referrals' => ['patient_id', 'source', 'employee_id', 'referred_at'],
            'consultations' => ['status', 'deleted_at', 'doctor_id', 'completed_at', 'episode_id'],
            'surgical_interventions' => ['surgical_request_id', 'performed_by', 'ended_at'],
            'surgical_requests' => ['episode_id'],
            'lab_request_items' => ['lab_request_id', 'resulted_by', 'resulted_at'],
            'lab_requests' => ['cancelled_at', 'episode_id'],
            'imaging_request_items' => ['imaging_request_id', 'resulted_by', 'resulted_at'],
            'imaging_requests' => ['cancelled_at', 'episode_id'],
            'care_record_procedures' => ['care_record_id', 'performed_by', 'performed_at'],
            'care_records' => ['episode_id'],
            'maternity_procedures' => ['maternity_record_id', 'deleted_at', 'performed_by', 'performed_at'],
            'maternity_records' => ['episode_id'],
        ];

        foreach ($columns as $table => $names) {
            foreach ($names as $name) {
                $this->assertTrue(Schema::hasColumn($table, $name), "{$table}.{$name} n’existe pas");
            }
        }

        // Et chaque mesure s'exécute (sur MySQL, une colonne inconnue échouerait ici).
        $employee = $this->employee('EMP-B8', 'Toutes', 'Mesures', User::factory()->create());
        foreach (BonusMeasure::cases() as $measure) {
            $this->assertSame([$employee->id => []], app(BonusMeter::class)->patients($measure, collect([$employee]), Carbon::parse('2026-09-01')));
        }
    }

    public function test_a_bonus_is_validated_only_once_the_threshold_is_reached_then_paid(): void
    {
        $hr = $this->user(self::HR);
        $recruiter = $this->employee('EMP-B9', 'Rabe', 'Hery');
        $category = $this->category(BonusMeasure::ReferredPatients, 2, [$recruiter], 20000);
        $this->referral($this->patient('A-26-0301'), $recruiter, '2026-09-02');

        $validate = fn () => $this->actingAs($hr)->post('/administration/bonus/awards', [
            'category_uuid' => $category->uuid,
            'employee_uuid' => $recruiter->uuid,
            'mois' => '2026-09',
        ]);

        $validate()->assertSessionHasErrors(['period' => 'Seuil non atteint : 1 patient(s) sur 2 ce mois-ci.']);

        $this->referral($this->patient('A-26-0302'), $recruiter, '2026-09-10');
        $this->actingAs($hr)->get('/administration/bonus?mois=2026-09')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Administration/Bonus/Index')
                ->where('board.summary.to_validate', 1)
                ->where('board.categories.0.employees.0.count', 2)
                ->where('board.categories.0.employees.0.reached', true)
                ->where('board.categories.0.employees.0.award', null));

        $validate()->assertSessionHasNoErrors();
        $award = BonusAward::query()->sole();
        $this->assertSame(BonusAwardStatus::Validated, $award->status);
        $this->assertSame(2, $award->patients_count);
        $this->assertSame('20000.00', (string) $award->amount);
        $this->assertEqualsCanonicalizing(['A-26-0301', 'A-26-0302'], $award->counted_patients);
        $this->assertSame($hr->id, $award->validated_by);

        // Un seul bonus en vigueur par catégorie, personne et mois.
        $validate()->assertSessionHasErrors(['period' => 'Ce bonus est déjà validé pour ce mois.']);

        // Changer la catégorie ensuite ne réécrit pas le bonus figé.
        $category->forceFill(['amount' => 50000])->save();
        $this->assertSame('20000.00', (string) $award->refresh()->amount);

        $this->actingAs($hr)->post("/administration/bonus/awards/{$award->uuid}/pay", ['note' => 'Avec la paie de septembre'])
            ->assertSessionHasNoErrors();
        $award->refresh();
        $this->assertSame(BonusAwardStatus::Paid, $award->status);
        $this->assertSame('Avec la paie de septembre', $award->payment_note);

        // Versé : il ne se modifie plus.
        $this->actingAs($hr)->post("/administration/bonus/awards/{$award->uuid}/cancel", ['reason' => 'Erreur'])
            ->assertSessionHasErrors('award');
        $this->assertSame(BonusAwardStatus::Paid, $award->refresh()->status);
    }

    public function test_a_cancelled_bonus_frees_the_month_and_nothing_is_ever_deleted(): void
    {
        $hr = $this->user(self::HR);
        $recruiter = $this->employee('EMP-B10', 'Rasoa', 'Nirina');
        $category = $this->category(BonusMeasure::ReferredPatients, 1, [$recruiter]);
        $this->referral($this->patient('A-26-0401'), $recruiter, '2026-09-04');

        $this->actingAs($hr)->post('/administration/bonus/awards', ['category_uuid' => $category->uuid, 'employee_uuid' => $recruiter->uuid, 'mois' => '2026-09']);
        $award = BonusAward::query()->sole();

        $this->actingAs($hr)->post("/administration/bonus/awards/{$award->uuid}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($hr)->post("/administration/bonus/awards/{$award->uuid}/cancel", ['reason' => 'Patient compté par erreur'])->assertSessionHasNoErrors();

        $award->refresh();
        $this->assertSame(BonusAwardStatus::Cancelled, $award->status);
        $this->assertNull($award->active_key);
        $this->assertSame('Patient compté par erreur', $award->cancel_reason);

        // Le mois est libéré : il se valide de nouveau, l'annulé reste dans l'historique.
        $this->actingAs($hr)->post('/administration/bonus/awards', ['category_uuid' => $category->uuid, 'employee_uuid' => $recruiter->uuid, 'mois' => '2026-09'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, BonusAward::query()->count());

        $this->expectException(\LogicException::class);
        $award->delete();
    }

    public function test_a_future_month_or_someone_outside_the_category_gets_no_bonus(): void
    {
        $hr = $this->user(self::HR);
        $member = $this->employee('EMP-B11', 'Membre', 'Un');
        $outsider = $this->employee('EMP-B12', 'Dehors', 'Deux');
        $category = $this->category(BonusMeasure::ReferredPatients, 1, [$member]);

        $this->actingAs($hr)->post('/administration/bonus/awards', ['category_uuid' => $category->uuid, 'employee_uuid' => $member->uuid, 'mois' => '2026-10'])
            ->assertSessionHasErrors('period');
        $this->actingAs($hr)->post('/administration/bonus/awards', ['category_uuid' => $category->uuid, 'employee_uuid' => $outsider->uuid, 'mois' => '2026-09'])
            ->assertSessionHasErrors('employee');
        $this->assertSame(0, BonusAward::query()->count());
    }

    public function test_each_gesture_needs_its_own_permission(): void
    {
        $reader = $this->user(['bonus_awards.view'], 'READER');
        $recruiter = $this->employee('EMP-B13', 'Lecture', 'Seule');
        $category = $this->category(BonusMeasure::ReferredPatients, 1, [$recruiter]);
        $this->referral($this->patient('A-26-0501'), $recruiter, '2026-09-04');

        // Sans bonus_categories.view : le tableau se lit, les catégories ne sont pas servies.
        $this->actingAs($reader)->get('/administration/bonus')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('categories', null)->where('staff', null)->has('board.categories', 1));

        $this->actingAs($reader)->post('/administration/bonus/awards', ['category_uuid' => $category->uuid, 'employee_uuid' => $recruiter->uuid, 'mois' => '2026-09'])->assertForbidden();
        $this->actingAs($reader)->post('/administration/bonus/categories', ['name' => 'X', 'measure' => 'CONSULTATIONS', 'threshold' => 1, 'amount' => 1])->assertForbidden();
        $this->actingAs($this->user([], 'NOBODY'))->get('/administration/bonus')->assertForbidden();
    }

    public function test_an_archived_category_keeps_its_awards_and_is_restored(): void
    {
        $hr = $this->user(self::HR);
        $category = $this->category(BonusMeasure::Consultations, 5, []);

        $this->actingAs($hr)->delete("/administration/bonus/categories/{$category->uuid}", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($hr)->delete("/administration/bonus/categories/{$category->uuid}", ['reason' => 'Remplacée'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($category);
        $this->assertSame('Remplacée', $category->refresh()->delete_reason);

        // Son nom reste pris : on la restaure, on ne la recrée pas.
        $this->actingAs($hr)->post('/administration/bonus/categories', ['name' => $category->name, 'measure' => 'CONSULTATIONS', 'threshold' => 5, 'amount' => 5000])
            ->assertSessionHasErrors('name');
        $this->actingAs($hr)->post("/administration/bonus/categories/{$category->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($category);
    }

    private function user(array $permissions, string $code = 'ADMINISTRATION'): User
    {
        $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function employee(string $number, string $lastName, string $firstName, ?User $account = null): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'sex' => 'M',
            'birth_date' => '1985-01-01',
            'active' => true,
            'user_id' => $account?->id,
        ]);
    }

    /** @param list<Employee> $employees */
    private function category(BonusMeasure $measure, int $threshold, array $employees, int $amount = 10000): BonusCategory
    {
        $category = BonusCategory::query()->create([
            'name' => 'Bonus '.$measure->value,
            'measure' => $measure,
            'threshold' => $threshold,
            'amount' => $amount,
            'active' => true,
        ]);
        $category->employees()->sync(collect($employees)->map->getKey()->all());

        return $category;
    }

    private function patient(string $number): Patient
    {
        return Patient::query()->create([
            'patient_number' => $number,
            'first_name' => 'Patient',
            'last_name' => $number,
            'birth_date' => '1990-01-01',
            'sex' => 'F',
        ]);
    }

    private function episode(Patient $patient): Episode
    {
        return Episode::query()->create([
            'patient_id' => $patient->id,
            'episode_number' => $patient->patient_number.'-'.fake()->unique()->numerify('##'),
            'started_at' => now()->subDay(),
        ]);
    }

    private function referral(Patient $patient, Employee $employee, string $date): PatientReferral
    {
        return PatientReferral::query()->create([
            'patient_id' => $patient->id,
            'episode_id' => $this->episode($patient)->id,
            'source' => ReferralSource::Employee,
            'employee_id' => $employee->id,
            'referrer_name' => "{$employee->last_name} {$employee->first_name}",
            'referred_at' => Carbon::parse($date.' 10:00'),
        ]);
    }

    private function consultation(Patient $patient, User $doctor, ?string $completedAt): Consultation
    {
        return Consultation::query()->create([
            'episode_id' => $this->episode($patient)->id,
            'doctor_id' => $doctor->id,
            'reason' => '<p>Motif</p>',
            'consulted_at' => Carbon::parse('2026-09-01 08:00'),
            'status' => $completedAt ? ConsultationStatus::Completed : ConsultationStatus::InProgress,
            'completed_at' => $completedAt ? Carbon::parse($completedAt) : null,
        ]);
    }
}
