<?php

namespace Tests\Feature\Administration;

use App\Actions\Administration\UpdateEmployeeAction;
use App\Enums\EmployeeRemunerationType;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Hr\Seniority;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-197 — rémunération déclarée, compte bancaire et ancienneté du dossier employé.
 */
class EmployeePayrollTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
        $this->hr = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_hr_records_a_salary_and_a_bank_account_with_the_employee(): void
    {
        $this->actingAs($this->hr)->post('/administration/employees', [
            ...$this->payload(),
            'remuneration_type' => 'SALARY',
            'remuneration_amount' => '450 000,50',
            'bank_account_number' => ' 00005  00001 123456789 ',
            'bank_account_holder' => '  RAKOTO  Jean ',
        ])->assertSessionHasNoErrors();

        $employee = Employee::query()->where('employee_number', 'PAY-001')->firstOrFail();
        $this->assertSame(EmployeeRemunerationType::Salary, $employee->remuneration_type);
        $this->assertSame('450000.50', $employee->remuneration_amount);
        $this->assertSame('00005 00001 123456789', $employee->bank_account_number);
        $this->assertSame('RAKOTO Jean', $employee->bank_account_holder);
    }

    public function test_a_salary_needs_its_amount_and_an_account_needs_its_holder(): void
    {
        $this->actingAs($this->hr)->post('/administration/employees', [
            ...$this->payload(),
            'remuneration_type' => 'ALLOWANCE',
            'bank_account_number' => '12345',
        ])->assertSessionHasErrors(['remuneration_amount', 'bank_account_holder']);

        $this->assertDatabaseMissing('employees', ['employee_number' => 'PAY-001']);
    }

    public function test_unpaid_never_keeps_an_amount(): void
    {
        $employee = $this->employee(['remuneration_type' => 'SALARY', 'remuneration_amount' => 300000]);

        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", [
            ...$this->payload(),
            'remuneration_type' => 'UNPAID',
        ])->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertSame(EmployeeRemunerationType::Unpaid, $employee->remuneration_type);
        $this->assertNull($employee->remuneration_amount);
    }

    public function test_omitting_the_payroll_fields_keeps_them(): void
    {
        $employee = $this->employee(['remuneration_type' => 'SALARY', 'remuneration_amount' => 300000, 'bank_account_number' => 'ACC-1', 'bank_account_holder' => 'X']);

        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", [
            ...$this->payload(), 'phone' => '0340000000',
        ])->assertSessionHasNoErrors();

        $this->assertSame('300000.00', $employee->refresh()->remuneration_amount);
        $this->assertSame('ACC-1', $employee->bank_account_number);
    }

    public function test_without_the_payroll_right_the_fields_are_refused_and_never_served(): void
    {
        $employee = $this->employee(['remuneration_type' => 'SALARY', 'remuneration_amount' => 300000, 'bank_account_number' => 'ACC-1', 'bank_account_holder' => 'X']);
        $this->deny($this->hr, ['employees.payroll.view', 'employees.payroll.update']);

        $this->actingAs($this->hr)->put("/administration/employees/{$employee->uuid}", [
            ...$this->payload(), 'remuneration_amount' => '1',
        ])->assertSessionHasErrors('remuneration_amount');
        $this->assertSame('300000.00', $employee->refresh()->remuneration_amount);

        $this->actingAs($this->hr)->get("/administration/employees/{$employee->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payroll', null)
                ->where('employee', fn ($data) => ! collect($data)->has('remuneration_amount') && ! collect($data)->has('bank_account_number')));

        $this->actingAs($this->hr)->get("/administration/employees/{$employee->uuid}/print")
            ->assertInertia(fn (Assert $page) => $page->where('payroll', null));

        // L'action refuse aussi : la requête n'est pas la seule garde.
        $this->expectException(AuthorizationException::class);
        app(UpdateEmployeeAction::class)->execute($employee, ['remuneration_amount' => 1], $this->hr);
    }

    public function test_the_file_serves_the_payroll_and_the_seniority_to_hr(): void
    {
        $employee = $this->employee([
            'hire_date' => now()->subYears(3)->subMonths(4)->toDateString(),
            'remuneration_type' => 'ALLOWANCE', 'remuneration_amount' => 150000,
        ]);

        $this->actingAs($this->hr)->get("/administration/employees/{$employee->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payroll.remuneration_type', 'ALLOWANCE')
                ->where('payroll.remuneration_label', 'Indemnité')
                ->where('employee.seniority.label', '3 ans 4 mois'));
    }

    public function test_seniority_is_computed_from_the_hire_date(): void
    {
        $today = CarbonImmutable::parse('2026-09-26');

        $this->assertSame('3 ans 4 mois', Seniority::of(CarbonImmutable::parse('2023-05-10'), $today)['label']);
        $this->assertSame('1 an', Seniority::of(CarbonImmutable::parse('2025-09-26'), $today)['label']);
        $this->assertSame('Moins d’un mois', Seniority::of(CarbonImmutable::parse('2026-08-27'), $today)['label']);
        $this->assertSame('1 mois', Seniority::of(CarbonImmutable::parse('2026-08-26'), $today)['label']);
        $this->assertTrue(Seniority::of(CarbonImmutable::parse('2027-01-01'), $today)['future']);
        $this->assertNull(Seniority::of(null, $today));
    }

    public function test_the_payroll_rights_belong_to_hr_by_default(): void
    {
        $this->assertTrue($this->hr->can('employees.payroll.view'));
        $this->assertTrue($this->hr->can('employees.payroll.update'));
        $reception = User::factory()->create(['role_id' => Role::query()->where('code', 'RECEPTION')->value('id')]);
        $this->assertFalse($reception->can('employees.payroll.view'));
    }

    /** @param list<string> $names */
    private function deny(User $user, array $names): void
    {
        foreach ($names as $name) {
            $user->permissions()->attach(Permission::query()->where('name', $name)->value('id'), ['effect' => 'deny']);
        }
        $user->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function employee(array $attributes = []): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'PAY-001', 'last_name' => 'Rakoto', 'first_name' => 'Jean', 'sex' => 'M', 'active' => true,
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['employee_number' => 'PAY-001', 'last_name' => 'Rakoto', 'first_name' => 'Jean', 'sex' => 'M', 'active' => true];
    }
}
