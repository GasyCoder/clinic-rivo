<?php

namespace App\Services\Administration;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\ProfessionalMailbox;

/**
 * ADR-190 — une adresse email professionnelle telle que la fiche employé du
 * site et le portail la lisent : une seule description, jamais un mot de passe.
 */
final class ProfessionalMailboxPresenter
{
    /** @return array<string, mixed> */
    public function present(ProfessionalMailbox $mailbox): array
    {
        $mailbox->loadMissing(['employee.jobTitle:id,label', 'employee.department:id,label', 'requester:id,name']);
        $employee = $mailbox->employee;

        return [
            'uuid' => $mailbox->uuid,
            'address' => $mailbox->address,
            'status' => $mailbox->status->value,
            'status_label' => $mailbox->status->label(),
            'open' => $mailbox->status->isOpen(),
            // Employé parti avec une boîte encore active : elle est à suspendre.
            'to_suspend' => $mailbox->status === ProfessionalMailboxStatus::Active && $mailbox->employeeDeparted(),
            'employee' => $employee ? [
                'uuid' => $employee->uuid,
                'name' => trim($employee->first_name.' '.$employee->last_name),
                'employee_number' => $employee->employee_number,
                'job_title' => $employee->jobTitle?->label ?? $employee->profession,
                'department' => $employee->department?->label,
                'active' => (bool) $employee->active && ! $employee->trashed(),
            ] : null,
            'request_note' => $mailbox->request_note,
            'requested_at' => $mailbox->requested_at?->toIso8601String(),
            'requested_by' => $mailbox->requester?->name ?? $mailbox->external_requested_by_name,
            'decided_at' => $mailbox->decided_at?->toIso8601String(),
            'decided_by' => $mailbox->external_decided_by_name,
            'rejection_reason' => $mailbox->rejection_reason,
            'activated_at' => $mailbox->activated_at?->toIso8601String(),
            'suspended_at' => $mailbox->suspended_at?->toIso8601String(),
            'suspended_by' => $mailbox->external_suspended_by_name,
            'suspension_reason' => $mailbox->suspension_reason,
            'cancelled_at' => $mailbox->cancelled_at?->toIso8601String(),
        ];
    }
}
