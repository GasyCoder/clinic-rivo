<?php

namespace App\Models;

use App\Enums\AdministrativeExitType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CDC §33.3 — the receivable created when a passage leaves with an unpaid
 * balance, whether the exit was authorised (dette validée) or merely
 * observed (évadé).
 *
 * ProtectsFinancialRecord, not Soft Delete: §34.2 rule 9 is explicit that
 * an escape must never erase the receivable, and rule 8 that a debt exit
 * is never the same thing as a settled invoice. There is deliberately no
 * `status`/`settled_at` column: the CDC defines no settlement workflow for
 * a receivable, and inventing one here would let a debt be marked paid
 * without any payment, cash session or receipt behind it — exactly what
 * ADR-012 reserves to Réception/Caisse.
 */
#[Fillable([
    'patient_id', 'episode_id', 'debt_number', 'origin', 'amount', 'currency',
    'reason', 'responsible_name', 'responsible_phone', 'responsible_relationship',
    'due_date', 'authorized_by', 'left_at_estimate', 'last_known_service',
    'comment', 'recorded_by',
])]
class PatientDebt extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'origin' => AdministrativeExitType::class,
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'left_at_estimate' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        // The receivable outlives an archived administrative record.
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
