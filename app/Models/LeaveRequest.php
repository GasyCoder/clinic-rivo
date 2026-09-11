<?php

namespace App\Models;

use App\Enums\LeaveRequestStatus;
use App\Exceptions\InvalidLeaveRequestTransitionException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

#[Fillable([
    'employee_id', 'interim_employee_id', 'leave_type_id', 'leave_address', 'emergency_phone',
    'days_requested', 'remaining_days_snapshot', 'projected_remaining_days_snapshot',
    'annual_quota_snapshot', 'day_count_method_snapshot', 'consumes_balance_snapshot',
    'requires_approval_snapshot', 'reason', 'requested_on', 'starts_on', 'returns_on',
    'status', 'decided_by', 'decided_at', 'decision_reason',
])]
class LeaveRequest extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'days_requested' => 'decimal:2',
            'remaining_days_snapshot' => 'decimal:2',
            'projected_remaining_days_snapshot' => 'decimal:2',
            'annual_quota_snapshot' => 'decimal:2',
            'consumes_balance_snapshot' => 'boolean',
            'requires_approval_snapshot' => 'boolean',
            'requested_on' => 'date',
            'starts_on' => 'date',
            'returns_on' => 'date',
            'status' => LeaveRequestStatus::class,
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function interimEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interim_employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'leave_type_id')->withTrashed();
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrDocument::class);
    }

    public function decide(LeaveRequestStatus $status, ?string $reason = null): void
    {
        if ($this->status !== LeaveRequestStatus::Pending
            || ! in_array($status, [LeaveRequestStatus::Approved, LeaveRequestStatus::Rejected], true)) {
            throw new InvalidLeaveRequestTransitionException($this, $status);
        }

        $this->status = $status;
        $this->decided_by = Auth::id();
        $this->decided_at = now();
        $this->decision_reason = $reason;
        $this->save();

        app(Auditor::class)->record(
            $status === LeaveRequestStatus::Approved ? 'approve' : 'reject',
            entity: $this,
            reason: $reason,
            module: $this->auditModule(),
        );
    }

    public function cancel(string $reason): void
    {
        if (! in_array($this->status, [LeaveRequestStatus::Pending, LeaveRequestStatus::Approved], true)) {
            throw new InvalidLeaveRequestTransitionException($this, LeaveRequestStatus::Cancelled);
        }

        $this->status = LeaveRequestStatus::Cancelled;
        $this->cancelled_by = Auth::id();
        $this->cancelled_at = now();
        $this->cancel_reason = $reason;
        $this->save();

        app(Auditor::class)->record(
            'cancel',
            entity: $this,
            reason: $reason,
            module: $this->auditModule(),
        );
    }

    protected function auditableSkipsChange(array $changes): bool
    {
        return array_key_exists('status', $changes);
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
