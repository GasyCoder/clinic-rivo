<?php

namespace App\Services\Administration;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\HrDocument;
use App\Models\HrReferenceValue;
use App\Models\LeaveRequest;
use App\Models\PlanningShift;

class HrPresenter
{
    /** @return array<string, mixed> */
    public function employeeOption(Employee $employee): array
    {
        return [
            'uuid' => $employee->uuid,
            'employee_number' => $employee->employee_number,
            'name' => $this->employeeName($employee),
            'department' => $employee->department?->label,
            'job_title' => $employee->jobTitle?->label ?? $employee->profession,
            'active' => $employee->active,
        ];
    }

    /** @return array<string, mixed> */
    public function employee(Employee $employee, bool $includePrivate = true): array
    {
        return [
            ...$this->employeeOption($employee),
            'civility' => $employee->civility?->value,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'sex' => $employee->sex->value,
            'birth_date' => $employee->birth_date?->toDateString(),
            'hire_date' => $employee->hire_date?->toDateString(),
            'birth_place' => $employee->birth_place,
            'department_uuid' => $employee->department?->uuid,
            'job_title_uuid' => $employee->jobTitle?->uuid,
            'identity_document_type' => $includePrivate ? $employee->identity_document_type?->value : null,
            'identity_document_number' => $includePrivate ? $employee->identity_document_number : null,
            'identity_document_issued_on' => $includePrivate ? $employee->identity_document_issued_on?->toDateString() : null,
            'identity_document_issued_at' => $includePrivate ? $employee->identity_document_issued_at : null,
            'marital_status' => $employee->marital_status?->value,
            'children_count' => $employee->children_count,
            'diploma' => $employee->diploma,
            'education_level' => $employee->education_level,
            'children_details' => $includePrivate ? $employee->children_details : null,
            'badge' => $employee->badge,
            'blouse' => $employee->blouse,
            'profession' => $employee->profession,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'address' => $employee->addressEntry?->label ?? $employee->address,
            'address_entry_uuid' => $employee->addressEntry?->uuid,
            'address_available' => $employee->addressEntry
                ? $employee->addressEntry->active && ! $employee->addressEntry->trashed()
                : true,
            'observation' => $employee->observation,
            'archived' => $employee->trashed(),
            'deleted_at' => $employee->deleted_at?->toIso8601String(),
            'delete_reason' => $employee->delete_reason,
            'created_at' => $employee->created_at?->toIso8601String(),
            'updated_at' => $employee->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function contract(EmploymentContract $contract): array
    {
        return [
            'uuid' => $contract->uuid,
            'employee' => $this->employeeOption($contract->employee),
            'contract_type_uuid' => $contract->contractType?->uuid,
            'contract_type' => $contract->contractType?->label,
            'reference_number' => $contract->reference_number,
            'signed_on' => $contract->signed_on?->toDateString(),
            'starts_on' => $contract->starts_on?->toDateString(),
            'trial_ends_on' => $contract->trial_ends_on?->toDateString(),
            'ends_on' => $contract->ends_on?->toDateString(),
            'observation' => $contract->observation,
            'archived' => $contract->trashed(),
            'delete_reason' => $contract->delete_reason,
            'created_at' => $contract->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function attendance(AttendanceRecord $record): array
    {
        return [
            'uuid' => $record->uuid,
            'employee' => $this->employeeOption($record->employee),
            'work_date' => $record->work_date?->toDateString(),
            'started_at' => $record->started_at?->toIso8601String(),
            'ended_at' => $record->ended_at?->toIso8601String(),
            'minutes' => $record->ended_at
                ? max(0, (int) $record->started_at->diffInMinutes($record->ended_at))
                : null,
            'observation' => $record->observation,
        ];
    }

    /** @return array<string, mixed> */
    public function leave(LeaveRequest $leave): array
    {
        return [
            'uuid' => $leave->uuid,
            'employee' => $this->employeeOption($leave->employee),
            'interim_employee' => $leave->interimEmployee
                ? $this->employeeOption($leave->interimEmployee)
                : null,
            'leave_address' => $leave->leave_address,
            'emergency_phone' => $leave->emergency_phone,
            'days_requested' => $leave->days_requested,
            'remaining_days_snapshot' => $leave->remaining_days_snapshot,
            'reason' => $leave->reason,
            'requested_on' => $leave->requested_on?->toDateString(),
            'starts_on' => $leave->starts_on?->toDateString(),
            'returns_on' => $leave->returns_on?->toDateString(),
            'status' => $leave->status->value,
            'status_label' => $leave->status->label(),
            'decided_at' => $leave->decided_at?->toIso8601String(),
            'decision_reason' => $leave->decision_reason,
            'decided_by' => $leave->decidedBy?->name,
            'cancelled_at' => $leave->cancelled_at?->toIso8601String(),
            'cancel_reason' => $leave->cancel_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function planning(PlanningShift $shift): array
    {
        return [
            'uuid' => $shift->uuid,
            'employee' => $this->employeeOption($shift->employee),
            'department_uuid' => $shift->department?->uuid,
            'department' => $shift->department?->label,
            'title' => $shift->title,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'observation' => $shift->observation,
        ];
    }

    /** @return array<string, mixed> */
    public function document(HrDocument $document): array
    {
        return [
            'uuid' => $document->uuid,
            'category' => $document->category->value,
            'category_label' => $document->category->label(),
            'title' => $document->title,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'issued_on' => $document->issued_on?->toDateString(),
            'notes' => $document->notes,
            'attestation_type' => $document->attestationType?->label,
            'employment_contract_uuid' => $document->employmentContract?->uuid,
            'leave_request_uuid' => $document->leaveRequest?->uuid,
            'archived' => $document->trashed(),
            'delete_reason' => $document->delete_reason,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function reference(HrReferenceValue $reference): array
    {
        return [
            'uuid' => $reference->uuid,
            'type' => $reference->type->value,
            'type_label' => $reference->type->label(),
            'code' => $reference->code,
            'label' => $reference->label,
            'active' => $reference->active,
            'position' => $reference->position,
            'archived' => $reference->trashed(),
            'delete_reason' => $reference->delete_reason,
        ];
    }

    private function employeeName(Employee $employee): string
    {
        return collect([$employee->last_name, $employee->first_name])
            ->filter()->join(' ');
    }
}
