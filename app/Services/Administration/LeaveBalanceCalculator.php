<?php

namespace App\Services\Administration;

use App\Enums\HrReferenceType;
use App\Enums\LeaveDayCountMethod;
use App\Enums\LeaveRequestStatus;
use App\Models\Employee;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class LeaveBalanceCalculator
{
    /** @return array<string, mixed> */
    public function preview(
        Employee $employee,
        HrReferenceValue $leaveType,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
        ?LeaveRequest $except = null,
        ?array $ruleOverrides = null,
    ): array {
        if ($leaveType->type !== HrReferenceType::LeaveType
            || ($ruleOverrides === null && (! $leaveType->active || $leaveType->trashed()))) {
            throw ValidationException::withMessages(['leave_type_uuid' => 'Ce type de demande n’est plus disponible.']);
        }

        if ($endsOn->isBefore($startsOn)) {
            throw ValidationException::withMessages(['returns_on' => 'La date de fin doit être postérieure ou égale à la date de début.']);
        }

        $rules = $ruleOverrides === null
            ? $this->rules($leaveType)
            : [...$this->defaultRules(), ...$ruleOverrides];
        $method = LeaveDayCountMethod::tryFrom((string) $rules['day_count_method'])
            ?? LeaveDayCountMethod::CalendarDaysInclusive;
        $days = $this->countDays($startsOn, $endsOn, $method);
        $maximum = $this->decimalOrNull($rules['max_days_per_request'] ?? null);

        if ($maximum !== null && $days > $maximum) {
            throw ValidationException::withMessages([
                'returns_on' => "Ce type de demande est limité à {$this->format($maximum)} jour(s) par demande.",
            ]);
        }

        $consumesBalance = (bool) ($rules['consumes_annual_balance'] ?? false);
        $quota = $consumesBalance ? $this->decimalOrNull($rules['annual_quota_days'] ?? null) : null;

        if ($consumesBalance && $startsOn->year !== $endsOn->year) {
            throw ValidationException::withMessages([
                'returns_on' => 'Une demande qui consomme le solde annuel doit rester dans la même année. Créez une demande par année.',
            ]);
        }

        if ($consumesBalance && $quota === null) {
            throw ValidationException::withMessages([
                'leave_type_uuid' => 'Le quota annuel de ce type de demande doit être configuré avant utilisation.',
            ]);
        }

        $approvedDays = null;
        $pendingDays = null;
        $balanceBefore = null;
        $balanceAfterRequest = null;
        $projectedBalance = null;

        if ($consumesBalance) {
            $baseQuery = LeaveRequest::query()
                ->where('employee_id', $employee->getKey())
                ->where('consumes_balance_snapshot', true)
                ->whereYear('starts_on', $startsOn->year)
                ->when($except, fn ($query) => $query->where($except->getKeyName(), '<>', $except->getKey()));

            $approvedDays = (float) (clone $baseQuery)
                ->where('status', LeaveRequestStatus::Approved->value)
                ->sum('days_requested');
            $pendingDays = (float) (clone $baseQuery)
                ->where('status', LeaveRequestStatus::Pending->value)
                ->sum('days_requested');
            $balanceBefore = $quota - $approvedDays;
            $balanceAfterRequest = $balanceBefore - $days;
            $projectedBalance = $balanceBefore - $pendingDays - $days;
        }

        return [
            'leave_type_uuid' => $leaveType->uuid,
            'leave_type_label' => $leaveType->label,
            'days_requested' => $this->format($days),
            'day_count_method' => $method->value,
            'day_count_method_label' => $method->label(),
            'consumes_annual_balance' => $consumesBalance,
            'annual_quota_days' => $quota === null ? null : $this->format($quota),
            'approved_days' => $approvedDays === null ? null : $this->format($approvedDays),
            'pending_days' => $pendingDays === null ? null : $this->format($pendingDays),
            'balance_before' => $balanceBefore === null ? null : $this->format($balanceBefore),
            'balance_after_request' => $balanceAfterRequest === null ? null : $this->format($balanceAfterRequest),
            'projected_balance' => $projectedBalance === null ? null : $this->format($projectedBalance),
            'requires_attachment' => (bool) ($rules['requires_attachment'] ?? false),
            'requires_approval' => (bool) ($rules['requires_approval'] ?? true),
            'max_days_per_request' => $maximum === null ? null : $this->format($maximum),
        ];
    }

    /** @return array<string, mixed> */
    public function rules(HrReferenceValue $leaveType): array
    {
        return [
            ...$this->defaultRules(),
            ...($leaveType->metadata ?? []),
        ];
    }

    /** @param array<string, mixed> $preview */
    public function assertSufficientBalance(array $preview): void
    {
        if (! $preview['consumes_annual_balance']) {
            return;
        }

        $available = (float) $preview['balance_before'];
        $requested = (float) $preview['days_requested'];
        if ($requested > $available) {
            throw ValidationException::withMessages([
                'leave' => "Solde annuel insuffisant : {$this->format($available)} jour(s) disponible(s) pour {$this->format($requested)} jour(s) demandé(s).",
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function defaultRules(): array
    {
        return [
            'consumes_annual_balance' => false,
            'annual_quota_days' => null,
            'max_days_per_request' => null,
            'requires_attachment' => false,
            'requires_approval' => true,
            'day_count_method' => LeaveDayCountMethod::CalendarDaysInclusive->value,
        ];
    }

    private function countDays(CarbonImmutable $startsOn, CarbonImmutable $endsOn, LeaveDayCountMethod $method): float
    {
        if ($method === LeaveDayCountMethod::CalendarDaysInclusive) {
            return (float) ($startsOn->diffInDays($endsOn) + 1);
        }

        $days = 0;
        for ($date = $startsOn; $date->lessThanOrEqualTo($endsOn); $date = $date->addDay()) {
            if (! $date->isWeekend()) {
                $days++;
            }
        }

        return (float) $days;
    }

    private function decimalOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function format(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
