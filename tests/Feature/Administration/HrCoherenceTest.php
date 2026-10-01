<?php

namespace Tests\Feature\Administration;

use App\Enums\DocumentDataContext;
use App\Enums\HrReferenceType;
use App\Models\AttendanceRecord;
use App\Models\EmploymentContract;
use App\Models\HrReferenceValue;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\HrReferenceSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * ADR-207 — des RH cohérentes : un congé en cours se voit à côté de « Actif »,
 * le document officiel d'un congé est un canevas, et les présences du jour
 * disent qui est là, parti, attendu ou en congé.
 */
class HrCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private User $administration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, HrReferenceSeeder::class]);
        $this->administration = User::factory()->create(['role_id' => Role::query()->where('code', 'ADMINISTRATION')->value('id')]);
    }

    public function test_an_employee_on_leave_stays_active_and_is_shown_on_leave(): void
    {
        $away = $this->employee('EMP-1', 'Rabe');
        $here = $this->employee('EMP-2', 'Rakoto');
        $this->leave($away, now()->subDay(), now()->addDays(3));
        $this->leave($here, now()->addWeek(), now()->addWeeks(2)); // à venir : pas en congé aujourd'hui

        $this->actingAs($this->administration)->get('/administration/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.active', 2)
                ->where('summary.on_leave', 1)
                ->where('employees.data', fn ($rows) => collect($rows)->firstWhere('uuid', $away->uuid)['on_leave']['until'] === now()->addDays(3)->toDateString()
                    && collect($rows)->firstWhere('uuid', $away->uuid)['active'] === true
                    && collect($rows)->firstWhere('uuid', $here->uuid)['on_leave'] === null));

        $this->actingAs($this->administration)->get('/administration/employees?status=on_leave')
            ->assertInertia(fn (Assert $page) => $page->where('employees.data', fn ($rows) => collect($rows)->pluck('uuid')->all() === [$away->uuid]));

        $this->actingAs($this->administration)->get('/administration')
            ->assertInertia(fn (Assert $page) => $page->where('summary.on_leave_today', 1));
    }

    public function test_a_leave_print_offers_the_leave_canevas_and_opens_generation_prefilled(): void
    {
        $employee = $this->employee('EMP-1', 'Rabe');
        $leave = $this->leave($employee, now(), now()->addDays(2));
        $leaveTemplate = $this->template(DocumentDataContext::EmployeeAndLeave, 'CONGE', 'Décision de congé');
        $this->template(DocumentDataContext::EmployeeOnly, 'ATTESTATION', 'Attestation de travail');

        $this->actingAs($this->administration)->get("/administration/leave/{$leave->uuid}/print")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Administration/Leave/Print')
                ->has('templates', 1)
                ->where('templates.0.uuid', $leaveTemplate->uuid));

        // Un seul canevas de congé : il est choisi d'office, avec la personne et la demande.
        $this->actingAs($this->administration)->get("/administration/generated-documents/create?employee={$employee->uuid}&leave={$leave->uuid}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('prefill.document_template_uuid', $leaveTemplate->uuid)
                ->where('prefill.employee_uuid', $employee->uuid)
                ->where('prefill.leave_request_uuid', $leave->uuid));
    }

    public function test_the_attendance_day_board_says_who_is_present_left_expected_or_on_leave(): void
    {
        $present = $this->employee('EMP-1', 'Present');
        $left = $this->employee('EMP-2', 'Parti');
        $expected = $this->employee('EMP-3', 'Attendu');
        $away = $this->employee('EMP-4', 'Conge');
        AttendanceRecord::query()->create(['employee_id' => $present->id, 'work_date' => now()->toDateString(), 'started_at' => now()->startOfDay()->addHours(7)]);
        AttendanceRecord::query()->create(['employee_id' => $left->id, 'work_date' => now()->toDateString(), 'started_at' => now()->startOfDay()->addHours(6), 'ended_at' => now()->startOfDay()->addHours(7)]);
        PlanningShift::query()->create(['employee_id' => $expected->id, 'starts_at' => now()->startOfDay()->addHours(8), 'ends_at' => now()->startOfDay()->addHours(16)]);
        $this->leave($away, now()->subDay(), now()->addDay());

        $this->actingAs($this->administration)->get('/administration/attendance')
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'today')
                ->where('board.counts.PRESENT', 1)
                ->where('board.counts.LEFT', 1)
                ->where('board.counts.EXPECTED', 1)
                ->where('board.counts.ON_LEAVE', 1)
                ->where('board.rows', fn ($rows) => collect($rows)->firstWhere('employee.uuid', $left->uuid)['minutes_today'] === 60));

        // Une période ou les sessions ouvertes demandées : l'historique, sans le tableau du jour.
        $this->actingAs($this->administration)->get('/administration/attendance?open=1')
            ->assertInertia(fn (Assert $page) => $page->where('view', 'history')->where('board', null));
    }

    /** Un salarié n'a qu'un contrat à la fois, et la période d'essai tient dans le contrat. */
    public function test_contracts_of_one_employee_never_overlap_and_trial_stays_inside(): void
    {
        $employee = $this->employee('EMP-C1', 'RAKOTO');
        $cdi = HrReferenceValue::query()->where('type', HrReferenceType::ContractType->value)->where('label', 'CDI')->firstOrFail();
        $cdd = HrReferenceValue::query()->where('type', HrReferenceType::ContractType->value)->where('label', 'CDD')->firstOrFail();
        $post = fn (array $data) => $this->actingAs($this->administration)->post('/administration/contracts', ['employee_uuid' => $employee->uuid, 'contract_type_uuid' => $cdd->uuid, ...$data]);

        $post(['starts_on' => '2026-01-01', 'ends_on' => '2026-03-31', 'trial_ends_on' => '2026-04-15'])->assertSessionHasErrors('trial_ends_on');
        $post(['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'])->assertSessionHasNoErrors();

        // Le même contrat saisi deux fois, ou un CDI qui commence pendant le CDD : refusés.
        $post(['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30'])->assertSessionHasErrors('starts_on');
        $post(['contract_type_uuid' => $cdi->uuid, 'starts_on' => '2026-06-01'])->assertSessionHasErrors('starts_on');
        // Après la fin du CDD : accepté.
        $post(['contract_type_uuid' => $cdi->uuid, 'starts_on' => '2026-07-01'])->assertSessionHasNoErrors();
        $this->assertSame(2, EmploymentContract::query()->where('employee_id', $employee->id)->count());

        // Un contrat archivé ne revient pas par-dessus celui qui l'a remplacé.
        $first = EmploymentContract::query()->where('employee_id', $employee->id)->orderBy('starts_on')->first();
        $first->delete();
        $post(['starts_on' => '2026-02-01', 'ends_on' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->actingAs($this->administration)->post("/administration/contracts/{$first->uuid}/restore")->assertSessionHasErrors('starts_on');
        $this->assertTrue(EmploymentContract::withTrashed()->findOrFail($first->id)->trashed());

        // Corriger un contrat ne se compare pas à lui-même.
        $current = EmploymentContract::query()->where('employee_id', $employee->id)->whereDate('starts_on', '2026-07-01')->sole();
        $this->actingAs($this->administration)->put("/administration/contracts/{$current->uuid}", [
            'employee_uuid' => $employee->uuid, 'contract_type_uuid' => $cdi->uuid, 'starts_on' => '2026-07-01', 'observation' => 'Corrigé',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_employee_with_an_open_attendance_session_is_not_archived(): void
    {
        $employee = $this->employee('EMP-A1', 'RABE');
        AttendanceRecord::query()->create(['employee_id' => $employee->id, 'work_date' => now()->toDateString(), 'started_at' => now()->subHour()]);

        $this->actingAs($this->administration)->delete("/administration/employees/{$employee->uuid}", ['reason' => 'Départ'])
            ->assertSessionHasErrors('reason');
        $this->assertFalse($employee->fresh()->trashed());
    }

    public function test_attendance_is_not_recorded_in_advance(): void
    {
        $employee = $this->employee('EMP-A2', 'SOA');

        $this->actingAs($this->administration)->post('/administration/attendance', [
            'employee_uuid' => $employee->uuid,
            'started_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertSessionHasErrors('started_at');
        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    private function employee(string $number, string $lastName): Employee
    {
        return Employee::query()->create(['employee_number' => $number, 'last_name' => $lastName, 'sex' => 'F', 'active' => true]);
    }

    private function leave(Employee $employee, $from, $to): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'reason' => 'Repos',
            'requested_on' => now()->subWeek()->toDateString(),
            'starts_on' => $from->toDateString(),
            'returns_on' => $to->toDateString(),
            'status' => 'APPROVED',
        ]);
    }

    private function template(DocumentDataContext $context, string $type, string $name): DocumentTemplate
    {
        return DocumentTemplate::query()->create([
            'lineage_id' => (string) Str::uuid(),
            'document_type' => $type,
            'data_context' => $context,
            'name' => $name,
            'content' => ['type' => 'doc', 'content' => []],
            'content_html' => '<p>Texte</p>',
            'active' => true,
        ]);
    }
}
