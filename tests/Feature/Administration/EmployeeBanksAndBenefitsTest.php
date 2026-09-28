<?php

namespace Tests\Feature\Administration;

use App\Actions\Administration\SaveEmployeeBenefitAction;
use App\Enums\EmployeeBenefitFrequency;
use App\Enums\HrReferenceType;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeBenefit;
use App\Models\HrReferenceValue;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-213 — module Banques, avantages et primes, et fiche employé enregistrée
 * section par section (enregistrement automatique).
 */
class EmployeeBanksAndBenefitsTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->hr = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    /* ------------------------------------------------------------------ */
    /* Module Banques                                                      */
    /* ------------------------------------------------------------------ */

    public function test_the_four_named_banks_are_in_the_referential(): void
    {
        $this->assertSame(['BMOI', 'BNI', 'BOA', 'SBM'], Bank::query()->orderBy('code')->pluck('code')->all());

        $this->actingAs($this->hr)->get('/administration/banks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Banks/Index')->has('banks', 4));
    }

    public function test_hr_adds_corrects_archives_and_restores_a_bank(): void
    {
        $this->actingAs($this->hr)->post('/administration/banks', [
            'code' => ' mcb ', 'name' => '  MCB   Madagascar ', 'bank_code' => '00012', 'swift_code' => 'mcbl mg mg',
        ])->assertSessionHasNoErrors();

        $bank = Bank::query()->where('code', 'MCB')->firstOrFail();
        $this->assertSame('MCB Madagascar', $bank->name);
        $this->assertSame('MCBLMGMG', $bank->swift_code);

        $this->actingAs($this->hr)->put("/administration/banks/{$bank->uuid}", [
            'code' => 'MCB', 'name' => 'MCB Madagascar SA', 'active' => false,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($bank->refresh()->active);

        $this->actingAs($this->hr)->delete("/administration/banks/{$bank->uuid}", ['reason' => 'Plus de partenariat'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted($bank);

        $this->actingAs($this->hr)->post("/administration/banks/{$bank->uuid}/restore")->assertSessionHasNoErrors();
        $this->assertNotSoftDeleted($bank->refresh());
    }

    public function test_the_same_bank_written_differently_is_refused_and_named(): void
    {
        foreach ([
            ['code' => 'BOAM', 'name' => 'Bank of Africa'],
            ['code' => 'XYZ', 'name' => 'BANK OF AFRICA MADAGASCAR'],
            ['code' => 'BOA2', 'name' => 'BOA Madagascar'],
            ['code' => 'boa', 'name' => 'Autre nom'],
        ] as $attempt) {
            $this->actingAs($this->hr)->post('/administration/banks', $attempt)
                ->assertSessionHasErrors(['name' => 'Cette banque existe déjà : « BOA — Bank of Africa Madagascar ».']);
        }

        // Une banque réellement différente passe, même quand son nom partage un mot.
        $this->actingAs($this->hr)->post('/administration/banks', ['code' => 'BFV', 'name' => 'BFV Société Générale'])->assertSessionHasNoErrors();
        $this->assertSame(5, Bank::query()->count());
    }

    public function test_an_archived_bank_is_proposed_to_be_restored_rather_than_recreated(): void
    {
        $bank = Bank::query()->where('code', 'SBM')->firstOrFail();
        $bank->delete_reason = 'Test';
        $bank->delete();

        $this->actingAs($this->hr)->post('/administration/banks', ['code' => 'SBMM', 'name' => 'SBM Bank Madagascar'])
            ->assertSessionHasErrors(['name' => 'Cette banque existe déjà, archivée : « SBM — SBM Bank Madagascar ». Restaurez-la plutôt que de la recréer.']);
    }

    public function test_the_bank_module_needs_the_hr_settings_rights(): void
    {
        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);

        $this->actingAs($reception)->get('/administration/banks')->assertForbidden();
        $this->actingAs($reception)->post('/administration/banks', ['code' => 'ABC', 'name' => 'ABC Banque'])->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* La fiche, section par section                                       */
    /* ------------------------------------------------------------------ */

    public function test_an_autosave_writes_only_what_it_sends_and_stays_on_the_file(): void
    {
        $employee = $this->employee(['phone' => '0340000000', 'birth_place' => 'Mahajanga']);

        $this->actingAs($this->hr)
            ->from("/administration/employees/{$employee->uuid}/edit")
            ->put("/administration/employees/{$employee->uuid}", ['phone' => '0321112233', '_autosave' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('status')
            ->assertRedirect("/administration/employees/{$employee->uuid}/edit");

        $employee->refresh();
        $this->assertSame('0321112233', $employee->phone);
        $this->assertSame('Mahajanga', $employee->birth_place);
        $this->assertSame('Rakoto', $employee->last_name);
    }

    public function test_a_sent_field_keeps_its_rule(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", ['last_name' => '', '_autosave' => true])
            ->assertSessionHasErrors('last_name');
        $this->assertSame('Rakoto', $employee->refresh()->last_name);
    }

    public function test_a_salary_sent_without_its_amount_is_refused(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", ['remuneration_type' => 'SALARY', '_autosave' => true])
            ->assertSessionHasErrors('remuneration_amount');

        // Le montant déjà enregistré suffit quand on ne change que le type.
        $employee->forceFill(['remuneration_type' => 'ALLOWANCE', 'remuneration_amount' => 150000])->save();
        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", ['remuneration_type' => 'SALARY', '_autosave' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame('150000.00', $employee->refresh()->remuneration_amount);
    }

    public function test_the_bank_of_an_account_comes_from_the_referential(): void
    {
        $employee = $this->employee();
        $boa = Bank::query()->where('code', 'BOA')->firstOrFail();

        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", [
            'bank_uuid' => $boa->uuid, 'bank_account_number' => '00005 00001', 'bank_account_holder' => 'RAKOTO Jean', '_autosave' => true,
        ])->assertSessionHasNoErrors();
        $this->assertSame($boa->getKey(), $employee->refresh()->bank_id);

        // Archivée ensuite : la fiche qui la porte la garde, une autre ne peut plus la choisir.
        $boa->delete_reason = 'Test';
        $boa->delete();
        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", ['bank_uuid' => $boa->uuid, '_autosave' => true])
            ->assertSessionHasNoErrors();

        $other = $this->employee(['employee_number' => 'PAY-002']);
        $this->actingAs($this->hr)->put("/administration/employees/{$other->uuid}", ['bank_uuid' => $boa->uuid, '_autosave' => true])
            ->assertSessionHasErrors('bank_uuid');

        $this->actingAs($this->hr)->get("/administration/employees/{$employee->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('payroll.bank.code', 'BOA')->where('payroll.bank.available', false));
    }

    public function test_without_the_payroll_right_the_bank_is_refused(): void
    {
        $employee = $this->employee();
        $this->hr = $this->deny($this->hr, ['employees.payroll.update']);

        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", [
            'bank_uuid' => Bank::query()->value('uuid'), '_autosave' => true,
        ])->assertSessionHasErrors('bank_uuid');
        $this->assertNull($employee->refresh()->bank_id);
    }

    public function test_a_short_creation_opens_the_file_in_sections(): void
    {
        $response = $this->actingAs($this->hr)->post('/administration/employees', [
            'last_name' => 'Rabe', 'sex' => 'F', 'active' => true, 'after' => 'edit',
        ])->assertSessionHasNoErrors();

        $employee = Employee::query()->where('last_name', 'Rabe')->firstOrFail();
        $response->assertRedirect("/administration/employees/{$employee->uuid}/edit");

        $this->actingAs($this->hr)->get("/administration/employees/{$employee->uuid}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Administration/Employees/Edit')
                ->has('banks', 4)
                ->where('benefitOptions.eligible', false)
                ->has('benefitOptions.types', 7));
    }

    /* ------------------------------------------------------------------ */
    /* Avantages et primes                                                 */
    /* ------------------------------------------------------------------ */

    public function test_a_doctor_receives_a_benefit_with_its_amount_and_reason(): void
    {
        $doctor = $this->employee(['job_title_id' => $this->jobTitle('DOCTOR')->getKey()]);

        $this->actingAs($this->hr)->post("/administration/employees/{$doctor->uuid}/benefits", [
            'benefit_type_uuid' => $this->benefitType('LODGING')->uuid,
            'amount' => '200 000',
            'reason' => 'Logement de fonction près de la clinique',
            'frequency' => 'MONTHLY',
            'starts_on' => '2026-09-01',
        ])->assertSessionHasNoErrors();

        $benefit = EmployeeBenefit::query()->where('employee_id', $doctor->getKey())->firstOrFail();
        $this->assertSame('200000.00', $benefit->amount);
        $this->assertSame($this->hr->getKey(), $benefit->created_by);

        $this->actingAs($this->hr)->get("/administration/employees/{$doctor->uuid}")
            ->assertInertia(fn (Assert $page) => $page->has('benefits', 1)
                ->where('benefits.0.type', 'Logement')
                ->where('benefits.0.frequency_label', 'Chaque mois'));
    }

    public function test_a_job_title_that_does_not_grant_benefits_is_refused(): void
    {
        $nurse = $this->employee(['job_title_id' => $this->jobTitle('GENERAL_NURSE')->getKey()]);

        $this->actingAs($this->hr)->post("/administration/employees/{$nurse->uuid}/benefits", $this->benefitPayload())
            ->assertSessionHasErrors(['benefit_type_uuid' => 'La fonction « Infirmier généraliste » n’ouvre pas droit aux avantages : cela se règle dans le module Fonctions.']);

        // Le module Fonctions l'ouvre : le même geste passe.
        $jobTitle = $this->jobTitle('GENERAL_NURSE');
        $this->actingAs($this->hr)->put("/administration/job-titles/{$jobTitle->uuid}", [
            'label' => $jobTitle->label, 'code' => $jobTitle->code, 'benefits_eligible' => true,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($jobTitle->refresh()->grantsBenefits());

        $this->actingAs($this->hr)->post("/administration/employees/{$nurse->uuid}/benefits", $this->benefitPayload())
            ->assertSessionHasNoErrors();
    }

    public function test_a_benefit_is_corrected_by_autosave_and_retired_with_its_reason(): void
    {
        $doctor = $this->employee(['job_title_id' => $this->jobTitle('DOCTOR')->getKey()]);
        $benefit = app(SaveEmployeeBenefitAction::class)->execute($doctor, [
            'benefit_type_uuid' => $this->benefitType('BONUS')->uuid, 'amount' => 50000, 'reason' => 'Prime de garde',
            'frequency' => 'MONTHLY', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31',
        ], $this->hr);

        $this->actingAs($this->hr)->put("/administration/employees/{$doctor->uuid}/benefits/{$benefit->uuid}", [
            'benefit_type_uuid' => $this->benefitType('BONUS')->uuid, 'amount' => '75000', 'reason' => 'Prime de garde de nuit',
            'frequency' => 'ONE_TIME', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', '_autosave' => true,
        ])->assertSessionHasNoErrors()->assertSessionMissing('status');

        $benefit->refresh();
        $this->assertSame('75000.00', $benefit->amount);
        $this->assertSame(EmployeeBenefitFrequency::OneTime, $benefit->frequency);
        $this->assertNull($benefit->ends_on, 'Une prime versée une fois n’a pas de fin.');

        $this->actingAs($this->hr)->delete("/administration/employees/{$doctor->uuid}/benefits/{$benefit->uuid}", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->hr)->delete("/administration/employees/{$doctor->uuid}/benefits/{$benefit->uuid}", ['reason' => 'Fin des gardes'])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($benefit);
        $this->assertSame('Fin des gardes', EmployeeBenefit::withTrashed()->find($benefit->getKey())->delete_reason);
    }

    public function test_benefits_follow_the_payroll_rights(): void
    {
        $doctor = $this->employee(['job_title_id' => $this->jobTitle('DOCTOR')->getKey()]);
        app(SaveEmployeeBenefitAction::class)->execute($doctor, [...$this->benefitPayload()], $this->hr);
        // Relu après le refus : les droits d'une instance sont mémorisés (once()).
        $this->hr = $this->deny($this->hr, ['employees.payroll.view', 'employees.payroll.update']);

        $this->actingAs($this->hr)->post("/administration/employees/{$doctor->uuid}/benefits", $this->benefitPayload())->assertForbidden();
        $this->actingAs($this->hr)->get("/administration/employees/{$doctor->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('benefits', null)->where('payroll', null));

        $this->expectException(AuthorizationException::class);
        app(SaveEmployeeBenefitAction::class)->execute($doctor, $this->benefitPayload(), $this->hr);
    }

    public function test_a_benefit_of_another_employee_is_not_reachable(): void
    {
        $doctor = $this->employee(['job_title_id' => $this->jobTitle('DOCTOR')->getKey()]);
        $other = $this->employee(['employee_number' => 'PAY-002', 'job_title_id' => $this->jobTitle('DOCTOR')->getKey()]);
        $benefit = app(SaveEmployeeBenefitAction::class)->execute($doctor, $this->benefitPayload(), $this->hr);

        $this->actingAs($this->hr)->put("/administration/employees/{$other->uuid}/benefits/{$benefit->uuid}", $this->benefitPayload())->assertNotFound();
        $this->actingAs($this->hr)->delete("/administration/employees/{$other->uuid}/benefits/{$benefit->uuid}", ['reason' => 'Erreur'])->assertNotFound();
    }

    public function test_the_benefit_types_are_managed_in_hr_settings(): void
    {
        $this->actingAs($this->hr)->get('/administration/settings')
            ->assertInertia(fn (Assert $page) => $page->has('references.BENEFIT_TYPE', 7)
                ->where('types', fn ($types) => collect($types)->contains('value', 'BENEFIT_TYPE')));
    }

    /** @return array<string, mixed> */
    private function benefitPayload(): array
    {
        return [
            'benefit_type_uuid' => $this->benefitType('TRANSPORT')->uuid,
            'amount' => '30000',
            'reason' => 'Transport de nuit',
            'frequency' => 'MONTHLY',
            'starts_on' => '2026-09-01',
        ];
    }

    private function jobTitle(string $code): HrReferenceValue
    {
        return HrReferenceValue::query()->ofType(HrReferenceType::JobTitle)->where('code', $code)->firstOrFail();
    }

    private function benefitType(string $code): HrReferenceValue
    {
        return HrReferenceValue::query()->ofType(HrReferenceType::BenefitType)->where('code', $code)->firstOrFail();
    }

    /** @param list<string> $names */
    private function deny(User $user, array $names): User
    {
        foreach ($names as $name) {
            $user->permissions()->attach(Permission::query()->where('name', $name)->value('id'), ['effect' => 'deny']);
        }

        return User::query()->findOrFail($user->getKey());
    }

    /** @param array<string, mixed> $attributes */
    private function employee(array $attributes = []): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'PAY-001', 'last_name' => 'Rakoto', 'first_name' => 'Jean', 'sex' => 'M', 'active' => true,
            ...$attributes,
        ]);
    }
}
