<?php

namespace App\Models;

use App\Enums\ProfessionalMailboxStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ADR-190 — l'adresse email professionnelle d'un employé, sur son site.
 *
 * Le site garde la demande et l'état de l'adresse ; la boîte elle-même vit
 * chez l'hébergeur, où seul le portail agit. Une adresse n'est jamais
 * supprimée : refusée, annulée ou suspendue, sa trace reste.
 */
#[Fillable([
    'employee_id', 'address', 'status', 'active_key', 'employee_active_key', 'request_note',
    'requested_at', 'requested_by', 'external_requested_by_uuid', 'external_requested_by_name',
    'decided_at', 'external_decided_by_uuid', 'external_decided_by_name', 'rejection_reason',
    'activated_at',
    'suspended_at', 'external_suspended_by_uuid', 'external_suspended_by_name', 'suspension_reason',
    'cancelled_at', 'cancelled_by', 'external_cancelled_by_uuid', 'external_cancelled_by_name',
])]
class ProfessionalMailbox extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        // Les clés d'unicité suivent le statut, jamais une saisie : tant que
        // l'adresse est ouverte, ni elle ni l'employé ne peuvent en porter une autre.
        static::saving(function (self $mailbox): void {
            $open = $mailbox->status->isOpen();
            $mailbox->active_key = $open ? $mailbox->address : null;
            $mailbox->employee_active_key = $open ? $mailbox->employee_id : null;
        });

        static::deleting(fn () => throw new LogicException('Une adresse email professionnelle ne se supprime jamais.'));
    }

    protected function casts(): array
    {
        return [
            'status' => ProfessionalMailboxStatus::class,
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** L'employé a quitté la clinique : sa boîte encore active est à suspendre. */
    public function employeeDeparted(): bool
    {
        $employee = $this->employee;

        return $employee === null || ! $employee->active || $employee->trashed();
    }
}
