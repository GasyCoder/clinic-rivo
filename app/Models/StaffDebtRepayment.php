<?php

namespace App\Models;

use App\Enums\StaffDebtRepaymentSource;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ADR-228 — un remboursement de dette : une retenue sur la paie du mois, ou des
 * espèces encaissées à la Caisse (avec son mouvement de caisse et son reçu). Jamais
 * supprimé : une paie annulée ou une erreur de caisse l'annule, avec un motif.
 */
#[Fillable([
    'staff_debt_id', 'employee_id', 'source', 'period', 'amount',
    'salary_payment_id', 'cash_session_id', 'cash_movement_id', 'receipt_number', 'note',
    'recorded_at', 'recorded_by', 'external_recorded_by_uuid', 'external_recorded_by_name',
    'reversed_at', 'reversed_by', 'external_reversed_by_uuid', 'external_reversed_by_name', 'reverse_reason',
    'reversal_cash_movement_id',
])]
class StaffDebtRepayment extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Un remboursement ne se supprime pas : il s’annule avec un motif.'));
    }

    protected function auditModule(): ?string
    {
        return 'hr';
    }

    protected function casts(): array
    {
        return [
            'source' => StaffDebtRepaymentSource::class,
            'period' => 'date',
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(StaffDebt::class, 'staff_debt_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function salaryPayment(): BelongsTo
    {
        return $this->belongsTo(SalaryPayment::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
