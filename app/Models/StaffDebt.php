<?php

namespace App\Models;

use App\Enums\SalaryPaymentMode;
use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * ADR-228 — une dette du personnel : demandée par l'employé depuis son compte,
 * décidée par le DG, versée hors RIVO, remboursée par retenue sur la paie du mois
 * ou en espèces à la Caisse.
 *
 * Reste dû = montant accordé − remboursements non annulés − montant remis. Il ne se
 * stocke pas : il se lit toujours sur les remboursements, qui ne mentent pas.
 * Jamais supprimée (ADR-010) : refusée, annulée, soldée ou remise.
 */
#[Fillable([
    'number', 'employee_id', 'employee_name', 'employee_number',
    'requested_amount', 'requested_installment', 'requested_first_period', 'reason', 'requested_at', 'requested_by',
    'status', 'pending_key',
    'amount', 'installment_amount', 'first_period', 'repayment_mode', 'decision_note', 'refusal_reason',
    'decided_at', 'decided_by', 'external_decided_by_uuid', 'external_decided_by_name',
    'disbursed_on', 'disbursement_mode', 'disbursement_reference', 'disbursement_note',
    'disbursed_at', 'disbursed_by', 'external_disbursed_by_uuid', 'external_disbursed_by_name',
    'settled_at',
    'written_off_amount', 'write_off_reason', 'written_off_at', 'written_off_by', 'external_written_off_by_uuid', 'external_written_off_by_name',
    'cancel_reason', 'cancelled_at', 'cancelled_by', 'external_cancelled_by_uuid', 'external_cancelled_by_name',
])]
class StaffDebt extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une dette du personnel ne se supprime pas : elle se refuse, s’annule ou se remet, avec un motif.'));
    }

    /** Une seule demande en attente par employé. */
    public static function pendingKey(int $employeeId): string
    {
        return "P-{$employeeId}";
    }

    protected function auditModule(): ?string
    {
        return 'hr';
    }

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'requested_installment' => 'decimal:2',
            'requested_first_period' => 'date',
            'requested_at' => 'datetime',
            'status' => StaffDebtStatus::class,
            'amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'first_period' => 'date',
            'repayment_mode' => StaffDebtRepaymentMode::class,
            'decided_at' => 'datetime',
            'disbursed_on' => 'date',
            'disbursement_mode' => SalaryPaymentMode::class,
            'disbursed_at' => 'datetime',
            'settled_at' => 'datetime',
            'written_off_amount' => 'decimal:2',
            'written_off_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(StaffDebtRepayment::class)->orderBy('recorded_at')->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Ce qui a été remboursé, en unités mineures : les remboursements annulés ne comptent pas. */
    public function repaidMinor(): int
    {
        $repayments = $this->relationLoaded('repayments')
            ? $this->repayments->whereNull('reversed_at')
            : $this->repayments()->whereNull('reversed_at')->get(['amount']);

        return (int) $repayments->sum(fn (StaffDebtRepayment $repayment) => Money::toMinor((string) $repayment->amount));
    }

    /** Le reste dû, en unités mineures ; zéro tant que rien n'est accordé. */
    public function balanceMinor(): int
    {
        if ($this->amount === null) {
            return 0;
        }

        return max(0, Money::toMinor((string) $this->amount) - $this->repaidMinor() - Money::toMinor((string) ($this->written_off_amount ?? 0)));
    }
}
