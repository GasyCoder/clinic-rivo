<?php

namespace App\Services\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PlanningShift;

/**
 * ADR-198 — les présences du jour, telles qu'elles sont enregistrées : qui est
 * là (session sans sortie), qui est parti, qui est attendu au planning sans
 * avoir pointé, qui est en congé. Aucun retard, aucune absence ni heure
 * supplémentaire n'est calculé (ADR-066) : l'écran constate, il ne juge pas.
 */
final class AttendanceTodayBoard
{
    public const STATES = ['PRESENT', 'EXPECTED', 'LEFT', 'ON_LEAVE', 'OFF'];

    public function __construct(
        private readonly HrPresenter $presenter,
        private readonly LeaveToday $leaveToday,
        private readonly InternshipDirectory $internships,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, counts: array<string, int>} */
    public function build(): array
    {
        $today = now()->toDateString();
        $employees = Employee::query()->where('active', true)
            ->with(['department' => fn ($query) => $query->withTrashed(), 'jobTitle' => fn ($query) => $query->withTrashed()])
            ->orderBy('last_name')->orderBy('first_name')->get();
        $ids = $employees->modelKeys();
        $interns = $this->internships->interns(Employee::query())->whereIn('id', $ids)->pluck('id')->flip();

        // Une session ouverte, quelle que soit sa date : la personne n'a pas pointé sa sortie.
        $open = AttendanceRecord::query()->whereIn('employee_id', $ids)->whereNull('ended_at')
            ->orderByDesc('started_at')->get()->unique('employee_id')->keyBy('employee_id');
        $todays = AttendanceRecord::query()->whereIn('employee_id', $ids)->whereDate('work_date', $today)
            ->get()->groupBy('employee_id');
        $shifts = PlanningShift::query()->whereIn('employee_id', $ids)
            ->where('starts_at', '<=', now()->endOfDay())
            ->where('ends_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')->get()->groupBy('employee_id');
        $leaves = $this->leaveToday->byEmployee($ids);

        $rows = $employees->map(function (Employee $employee) use ($open, $todays, $shifts, $leaves, $interns): array {
            $session = $open->get($employee->getKey());
            $sessions = $todays->get($employee->getKey(), collect());
            $shift = $shifts->get($employee->getKey())?->first();
            $leave = $leaves[$employee->getKey()] ?? null;
            $minutes = $sessions->whereNotNull('ended_at')
                ->sum(fn (AttendanceRecord $record) => max(0, (int) $record->started_at->diffInMinutes($record->ended_at)));

            $state = match (true) {
                $session !== null => 'PRESENT',
                $sessions->isNotEmpty() => 'LEFT',
                $leave !== null => 'ON_LEAVE',
                $shift !== null => 'EXPECTED',
                default => 'OFF',
            };

            return [
                'employee' => [...$this->presenter->employeeOption($employee), 'is_intern' => $interns->has($employee->getKey())],
                'state' => $state,
                'open_session' => $session ? [
                    'uuid' => $session->uuid,
                    'started_at' => $session->started_at?->toIso8601String(),
                    'observation' => $session->observation,
                ] : null,
                'sessions_today' => $sessions->count(),
                'minutes_today' => $minutes,
                'last_exit' => $sessions->max(fn (AttendanceRecord $record) => $record->ended_at?->toIso8601String()),
                'shift' => $shift ? [
                    'starts_at' => $shift->starts_at?->toIso8601String(),
                    'ends_at' => $shift->ends_at?->toIso8601String(),
                    'kind' => $shift->kind?->value,
                    'title' => $shift->title,
                ] : null,
                'leave' => $leave,
            ];
        })->values();

        return [
            'rows' => $rows->all(),
            'counts' => collect(self::STATES)->mapWithKeys(fn (string $state) => [$state => $rows->where('state', $state)->count()])->all(),
        ];
    }
}
