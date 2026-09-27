<?php

namespace App\Services\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * ADR-066 — data integrity, not a work-time rule: one person cannot be present
 * twice at the same moment. A new or corrected session is refused when it
 * overlaps another session of the same employee, or when a session of this
 * employee is still open (no exit time).
 */
class AttendanceOverlapGuard
{
    public function assertFree(Employee $employee, string $startedAt, ?string $endedAt, ?AttendanceRecord $except = null): void
    {
        $start = CarbonImmutable::parse($startedAt);
        $end = $endedAt ? CarbonImmutable::parse($endedAt) : null;

        $conflict = AttendanceRecord::query()
            ->where('employee_id', $employee->getKey())
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            // The other session ends after this one starts (or is still open)…
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            // …and starts before this one ends (an open session never ends).
            ->when($end, fn ($query) => $query->where('started_at', '<', $end))
            ->orderBy('started_at')
            ->first();

        if ($conflict === null) {
            return;
        }

        throw ValidationException::withMessages([
            'started_at' => $conflict->ended_at === null
                ? "{$employee->first_name} {$employee->last_name} a déjà une présence ouverte depuis le {$conflict->started_at->format('d/m/Y H:i')} : enregistrez d’abord sa sortie."
                : "Cette présence chevauche celle du {$conflict->started_at->format('d/m/Y H:i')} au {$conflict->ended_at->format('d/m/Y H:i')}.",
        ]);
    }
}
