<?php

namespace Tests\Feature\Administration;

use App\Models\AttendanceRecord;
use App\Models\BonusCategory;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Hr\EmployeeUsage;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-236 — supprimer depuis la liste des employés : archiver une ligne ou une sélection,
 * restaurer, supprimer définitivement un dossier archivé qui n'a servi nulle part, et
 * repérer les doublons.
 */
class EmployeeDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $administration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->administration = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_the_usage_registry_covers_every_table_that_points_to_employees(): void
    {
        $known = collect(EmployeeUsage::REFERENCES)
            ->flatMap(fn (array $columns, string $table) => collect($columns)->map(fn (array $column) => "{$table}.{$column[0]}"))
            ->merge(collect(EmployeeUsage::DETACHED)->map(fn (string $column, string $table) => "{$table}.{$column}")->values())
            ->sort()->values()->all();

        $actual = collect(Schema::getTables())->pluck('name')
            ->flatMap(fn (string $table) => collect(Schema::getForeignKeys($table))
                ->filter(fn (array $key) => $key['foreign_table'] === 'employees')
                ->flatMap(fn (array $key) => collect($key['columns'])->map(fn (string $column) => "{$table}.{$column}")))
            ->sort()->values()->all();

        $this->assertSame($actual, $known, 'Une table pointe vers employees sans être dans EmployeeUsage (ADR-236).');
    }

    public function test_an_employee_is_archived_from_the_list_and_stays_on_it(): void
    {
        $employee = $this->employee('EMP-1', 'Rabe');

        $this->actingAs($this->administration)
            ->from('/administration/employees?status=active')
            ->delete("/administration/employees/{$employee->uuid}", ['reason' => 'Doublon de EMP-2', 'back' => true])
            ->assertRedirect('/administration/employees?status=active');

        $this->assertSoftDeleted($employee);
        $this->assertSame('Doublon de EMP-2', $employee->fresh()->delete_reason ?? Employee::withTrashed()->find($employee->id)->delete_reason);
    }

    public function test_a_never_used_archived_record_is_force_deleted_and_audited(): void
    {
        $user = $this->forceDeleter();
        $employee = $this->employee('EMP-9', 'Doublon');
        $employee->delete_reason = 'Saisi deux fois';
        $employee->delete();
        $category = BonusCategory::query()->create(['name' => 'Recommandations', 'measure' => 'REFERRED_PATIENTS', 'threshold' => 3, 'amount' => 1000]);
        DB::table('bonus_category_employees')->insert(['bonus_category_id' => $category->id, 'employee_id' => $employee->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->delete("/administration/employees/{$employee->uuid}/force")
            ->assertRedirect('/administration/employees?status=archived')
            ->assertSessionHasNoErrors();

        $this->assertNull(Employee::withTrashed()->find($employee->id));
        $this->assertDatabaseMissing('bonus_category_employees', ['employee_id' => $employee->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.force_delete']);
    }

    public function test_a_record_that_served_or_is_not_archived_is_never_destroyed(): void
    {
        $user = $this->forceDeleter();
        $active = $this->employee('EMP-1', 'Rabe');
        $used = $this->employee('EMP-2', 'Rakoto');
        AttendanceRecord::query()->create(['employee_id' => $used->id, 'work_date' => now()->subDay()->toDateString(), 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHours(8)]);
        $used->delete_reason = 'Départ';
        $used->delete();

        $this->actingAs($user)->delete("/administration/employees/{$active->uuid}/force")->assertForbidden();

        $this->actingAs($user)->delete("/administration/employees/{$used->uuid}/force")
            ->assertSessionHasErrors(['employee' => 'Le dossier EMP-2 a servi (1 présence) : il reste archivé, on ne détruit pas un historique.']);
        $this->assertNotNull(Employee::withTrashed()->find($used->id));
    }

    public function test_force_delete_requires_its_own_permission(): void
    {
        $employee = $this->employee('EMP-9', 'Doublon');
        $employee->delete_reason = 'x';
        $employee->delete();

        $this->actingAs($this->administration)->delete("/administration/employees/{$employee->uuid}/force")->assertForbidden();
    }

    public function test_a_selection_is_archived_each_record_judged_separately(): void
    {
        $free = $this->employee('EMP-1', 'Rabe');
        $present = $this->employee('EMP-2', 'Rakoto');
        AttendanceRecord::query()->create(['employee_id' => $present->id, 'work_date' => now()->subHour()->toDateString(), 'started_at' => now()->subHour()]);

        $this->actingAs($this->administration)->from('/administration/employees')
            ->post('/administration/employees/bulk', ['action' => 'archive', 'uuids' => [$free->uuid, $present->uuid], 'reason' => 'Doublons'])
            ->assertRedirect('/administration/employees')
            ->assertSessionHas('bulk_report', fn (array $report) => $report['done'] === 1
                && count($report['failed']) === 1
                && str_contains($report['failed'][0]['message'], 'encore ouverte'));

        $this->assertSoftDeleted($free);
        $this->assertNotSoftDeleted($present);
    }

    public function test_a_bulk_archive_needs_a_reason_and_its_permission(): void
    {
        $employee = $this->employee('EMP-1', 'Rabe');

        $this->actingAs($this->administration)->post('/administration/employees/bulk', ['action' => 'archive', 'uuids' => [$employee->uuid]])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->administration)->post('/administration/employees/bulk', ['action' => 'force_delete', 'uuids' => [$employee->uuid]])
            ->assertForbidden();
    }

    public function test_duplicates_are_flagged_and_filtered(): void
    {
        $a = $this->employee('EMP-1', 'Rasoa', 'Vola', '1990-04-02');
        $b = $this->employee('EMP-2', 'RASOA', 'Volà', '1990-04-02');
        $this->employee('EMP-3', 'Rasoa', 'Vola', '1985-01-01'); // homonyme né un autre jour
        $this->employee('EMP-4', 'Rabe', 'Hery');

        $this->actingAs($this->administration)->get('/administration/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.duplicates', 2)
                ->where('employees.data', fn ($rows) => collect($rows)->firstWhere('uuid', $a->uuid)['duplicates'][0]['number'] === 'EMP-2'
                    && collect($rows)->firstWhere('employee_number', 'EMP-3')['duplicates'] === []));

        $this->actingAs($this->administration)->get('/administration/employees?status=duplicates')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data', fn ($rows) => collect($rows)->pluck('uuid')->sort()->values()->all() === collect([$a->uuid, $b->uuid])->sort()->values()->all()));
    }

    public function test_archived_rows_say_what_prevents_their_deletion(): void
    {
        $free = $this->employee('EMP-1', 'Rabe');
        $used = $this->employee('EMP-2', 'Rakoto');
        AttendanceRecord::query()->create(['employee_id' => $used->id, 'work_date' => now()->subDays(2)->toDateString(), 'started_at' => now()->subDays(2), 'ended_at' => now()->subDays(2)->addHours(8)]);
        AttendanceRecord::query()->create(['employee_id' => $used->id, 'work_date' => now()->subDay()->toDateString(), 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHours(8)]);
        foreach ([$free, $used] as $employee) {
            $employee->delete_reason = 'x';
            $employee->delete();
        }

        $this->actingAs($this->administration)->get('/administration/employees?status=archived')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data', fn ($rows) => collect($rows)->firstWhere('uuid', $free->uuid)['deletion_blockers'] === []
                && collect($rows)->firstWhere('uuid', $used->uuid)['deletion_blockers'] === ['2 présences']));
    }

    private function forceDeleter(): User
    {
        $user = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
        DB::table('user_permissions')->insert([
            'user_id' => $user->id,
            'permission_id' => Permission::query()->where('name', 'employees.force_delete')->value('id'),
            'effect' => 'allow',
            'source' => 'MANUAL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function employee(string $number, string $lastName, ?string $firstName = null, ?string $birthDate = null): Employee
    {
        return Employee::query()->create([
            'employee_number' => $number, 'last_name' => $lastName, 'first_name' => $firstName,
            'birth_date' => $birthDate, 'sex' => 'F', 'active' => true,
        ]);
    }
}
