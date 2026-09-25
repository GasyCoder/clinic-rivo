<?php

namespace Database\Seeders;

use App\Actions\Administration\ApproveLeaveRequestAction;
use App\Actions\Administration\CreateAttendanceRecordAction;
use App\Actions\Administration\CreateEmployeeAction;
use App\Actions\Administration\CreateEmploymentContractAction;
use App\Actions\Administration\CreateLeaveRequestAction;
use App\Actions\Administration\CreatePlanningShiftAction;
use App\Enums\HrReferenceType;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\LocalOnly;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * ADR-086 — un personnel fictif pour essayer le module RH en local : des
 * employés dans plusieurs départements, leurs contrats (dont un qui finit
 * bientôt), des présences d'aujourd'hui (une encore ouverte), des congés en
 * attente et acceptés, une stagiaire et son stage, et les deux plannings de
 * la semaine : le service et les gardes de nuit (ADR-194).
 *
 * Tout passe par les vraies Actions, donc par leurs règles (chevauchements,
 * solde de congés) et par l'audit. Appelable nommément, jamais par
 * `DatabaseSeeder` ; ne fait rien si des employés existent déjà.
 */
class DevelopmentHrSeeder extends Seeder
{
    use LocalOnly;

    private const ACTOR_EMAIL = 'user@rivo.test';

    /** Nom, prénom, sexe, département, fonction, naissance, entrée. */
    private const PEOPLE = [
        ['RAKOTOBE', 'Hanitra', 'F', 'Chirurgie', 'Infirmier généraliste', '1991-03-12', '2019-02-01'],
        ['ANDRIAMASY', 'Tojo', 'M', 'Médecine', 'Médecin', '1984-07-30', '2016-09-15'],
        ['RASOANAIVO', 'Lalao', 'F', 'Administration', 'Admin', '1995-11-02', '2021-05-03'],
        ['RAZAFY', 'Mamy', 'M', 'Pharmacie', 'Pharmacien', '1988-01-19', '2018-01-08'],
        ['RANDRIA', 'Voahangy', 'F', 'Maternité', 'Sage-femme', '1990-06-25', '2020-03-16'],
        ['RABE', 'Fidy', 'M', 'Laboratoire', 'Laborantin', '1993-09-08', '2022-10-10'],
        ['RAHARISON', 'Nirina', 'F', 'Chirurgie', 'Infirmier de bloc', '1987-12-14', '2017-06-01'],
        ['RATSIMBA', 'Hery', 'M', 'Support', 'Gardien', '1998-04-05', '2024-01-15'],
    ];

    public function run(): void
    {
        $this->ensureLocal();

        if (config('rivo.site.type') !== 'clinic') {
            throw new LogicException('Le personnel existe uniquement sur un site opérationnel.');
        }

        if (Employee::query()->withTrashed()->exists()) {
            $this->command?->info('Des employés existent déjà : rien n’est ajouté.');

            return;
        }

        $actor = User::query()->where('email', self::ACTOR_EMAIL)->firstOrFail();
        $today = CarbonImmutable::today();

        $employees = collect(self::PEOPLE)->values()->map(function (array $person, int $index) use ($actor): Employee {
            [$last, $first, $sex, $department, $job, $born, $hired] = $person;

            return app(CreateEmployeeAction::class)->execute([
                'employee_number' => sprintf('EMP-%04d', $index + 1),
                'last_name' => $last,
                'first_name' => $first,
                'sex' => $sex,
                'birth_date' => $born,
                'hire_date' => $hired,
                'department_uuid' => $this->reference(HrReferenceType::Department, $department),
                'job_title_uuid' => $this->reference(HrReferenceType::JobTitle, $job),
                'phone' => sprintf('03400%05d', 11111 * ($index + 1) % 100000),
                'active' => true,
            ], $actor);
        });

        $cdi = $this->reference(HrReferenceType::ContractType, 'CDI');
        $cdd = $this->reference(HrReferenceType::ContractType, 'CDD');

        foreach ($employees as $index => $employee) {
            // Un CDD qui se termine dans trois semaines : « fin proche ».
            $fixedTerm = $index === 7 && $cdd;
            app(CreateEmploymentContractAction::class)->execute([
                'employee_uuid' => $employee->uuid,
                'contract_type_uuid' => $fixedTerm ? $cdd : ($cdi ?? $cdd),
                'reference_number' => sprintf('CT-%s-%03d', $today->format('Y'), $index + 1),
                'signed_on' => $employee->hire_date?->toDateString(),
                'starts_on' => $employee->hire_date?->toDateString() ?? $today->subYear()->toDateString(),
                'ends_on' => $fixedTerm ? $today->addWeeks(3)->toDateString() : null,
            ], $actor);
        }

        // Présences d'aujourd'hui : six sorties enregistrées, une encore ouverte.
        foreach ($employees->take(7) as $index => $employee) {
            $start = $today->setTime(7, 30)->addMinutes($index * 5);
            app(CreateAttendanceRecordAction::class)->execute([
                'employee_uuid' => $employee->uuid,
                'started_at' => $start->toDateTimeString(),
                'ended_at' => $index === 0 ? null : $start->addHours(8)->toDateTimeString(),
            ], $actor);
        }

        // Congés : un accepté, deux en attente de décision.
        $annual = $this->reference(HrReferenceType::LeaveType, 'Congé annuel');
        $permission = $this->reference(HrReferenceType::LeaveType, 'Permission');
        $leave = fn (Employee $employee, string $type, int $from, int $days, string $reason) => app(CreateLeaveRequestAction::class)->execute([
            'employee_uuid' => $employee->uuid,
            'leave_type_uuid' => $type,
            'reason' => $reason,
            'starts_on' => $today->addDays($from)->toDateString(),
            'returns_on' => $today->addDays($from + $days)->toDateString(),
        ], $actor);

        $approved = $leave($employees[3], $annual, 10, 5, 'Congé annuel planifié');
        app(ApproveLeaveRequestAction::class)->execute($approved, 'Validé par la direction', $actor);
        $leave($employees[1], $annual, 20, 7, 'Congé familial');
        $leave($employees[5], $permission ?? $annual, 3, 1, 'Rendez-vous administratif');

        // ADR-194 — une stagiaire sage-femme, encadrée par la sage-femme en poste.
        $intern = app(CreateEmployeeAction::class)->execute([
            'employee_number' => 'STG-0001',
            'last_name' => 'RAVELO',
            'first_name' => 'Tiana',
            'sex' => 'F',
            'birth_date' => '2003-02-11',
            'hire_date' => $today->subWeeks(2)->toDateString(),
            'department_uuid' => $this->reference(HrReferenceType::Department, 'Maternité'),
            'job_title_uuid' => $this->reference(HrReferenceType::JobTitle, 'Sage-femme'),
            'active' => true,
        ], $actor);
        $internshipType = $this->reference(HrReferenceType::ContractType, 'Stagiaire');
        $midwifery = $this->reference(HrReferenceType::InternshipField, 'Sage-femme');

        if ($internshipType && $midwifery) {
            app(CreateEmploymentContractAction::class)->execute([
                'employee_uuid' => $intern->uuid,
                'contract_type_uuid' => $internshipType,
                'starts_on' => $today->subWeeks(2)->toDateString(),
                'ends_on' => $today->addMonths(2)->toDateString(),
                'internship_field_uuid' => $midwifery,
                'internship_school' => 'Institut de formation paramédicale',
                'internship_level' => '3e année',
                'internship_supervisor_uuid' => $employees[4]->uuid,
            ], $actor);
        }

        // ADR-194 — les deux plannings de la semaine : le service de jour de
        // chacun, et des gardes de nuit (19 h → 7 h le lendemain).
        foreach ($employees as $index => $employee) {
            $day = $today->addDays($index % 5)->setTime(7, 0);
            app(CreatePlanningShiftAction::class)->execute([
                'employee_uuid' => $employee->uuid,
                'department_uuid' => $employee->department?->uuid,
                'kind' => 'SHIFT',
                'title' => 'Service de jour',
                'starts_at' => $day->toDateTimeString(),
                'ends_at' => $day->addHours(8)->toDateTimeString(),
            ], $actor);
        }

        foreach ([$employees[0], $employees[1], $employees[4], $intern, $employees[6], $employees[7]] as $index => $employee) {
            $night = $today->addDays($index)->setTime(19, 0);
            app(CreatePlanningShiftAction::class)->execute([
                'employee_uuid' => $employee->uuid,
                'department_uuid' => $employee->department?->uuid,
                'kind' => 'ON_CALL',
                'title' => 'Garde de nuit',
                'starts_at' => $night->toDateTimeString(),
                'ends_at' => $night->addHours(12)->toDateTimeString(),
            ], $actor);
        }
    }

    /** L'UUID d'une valeur du référentiel RH ; `null` si le site ne la connaît pas. */
    private function reference(HrReferenceType $type, string $label): ?string
    {
        return HrReferenceValue::query()->where('type', $type->value)->where('label', $label)->value('uuid');
    }
}
