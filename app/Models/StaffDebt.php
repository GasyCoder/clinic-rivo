<?php

namespace App\Models;

use App\Enums\SalaryPaymentMode;
use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * ADR-228 — une dette du personnel : demandée par l'employé depuis son compte,
 * décidée par le DG, versée hors RIVO, remboursée par retenue sur la paie du mois
 * ou en espèces à la Caisse.
 *
 * Reste dû = montant accordé + intérêt + pénalités non remises − remboursements non annulés
 * − montant remis. Il ne se stocke pas : il se lit toujours sur les remboursements, qui ne
 * mentent pas. ADR-229 — l'intérêt est figé à la décision du DG, selon les tranches du site.
 * ADR-230 — la règle de pénalité de retard aussi ; un remboursement paie d'abord le montant et
 * son intérêt, les pénalités ensuite (jamais de pénalité sur une pénalité).
 * Jamais supprimée (ADR-010) : refusée, annulée, soldée ou remise.
 */
#[Fillable([
    'number', 'employee_id', 'employee_name', 'employee_number',
    'requested_amount', 'requested_installment', 'requested_first_period', 'requested_interest_amount', 'reason', 'requested_at', 'requested_by',
    'status', 'pending_key',
    'amount', 'installment_amount', 'interest_amount', 'interest_mode', 'interest_value', 'interest_waived',
    'penalty_rate', 'penalty_grace_days', 'penalty_cap_rate', 'schedule_offset',
    'first_period', 'repayment_mode', 'decision_note', 'derogations', 'refusal_reason',
    'decided_at', 'decided_by', 'external_decided_by_uuid', 'external_decided_by_name',
    'disbursed_on', 'disbursement_mode', 'disbursement_reference', 'disbursement_note',
    'disbursed_at', 'disbursed_by', 'external_disbursed_by_uuid', 'external_disbursed_by_name',
    'settled_at', 'arrears_notified_for',
    'departure_settled_at', 'departure_settled_by', 'external_departure_settled_by_uuid', 'external_departure_settled_by_name', 'departure_terms',
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
        return 'finance';
    }

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'requested_installment' => 'decimal:2',
            'requested_first_period' => 'date',
            'requested_interest_amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'status' => StaffDebtStatus::class,
            'amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'interest_value' => 'decimal:2',
            'interest_waived' => 'boolean',
            'penalty_rate' => 'decimal:2',
            'penalty_grace_days' => 'integer',
            'penalty_cap_rate' => 'decimal:2',
            'schedule_offset' => 'decimal:2',
            'departure_settled_at' => 'datetime',
            'departure_terms' => 'array',
            'derogations' => 'array',
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

    /** ADR-230 — les pénalités de retard liquidées, remises comprises. */
    public function penalties(): HasMany
    {
        return $this->hasMany(StaffDebtPenalty::class)->orderBy('period')->orderBy('id');
    }

    public function departureSettler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'departure_settled_by');
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

    /** Ce qui est à rembourser en tout : le montant accordé et son intérêt ; zéro tant que rien n'est accordé. */
    public function totalDueMinor(): int
    {
        if ($this->amount === null) {
            return 0;
        }

        return Money::toMinor((string) $this->amount) + Money::toMinor((string) ($this->interest_amount ?? 0));
    }

    /** Ce que la demande porterait à rembourser : le montant demandé et l'intérêt de sa tranche à la demande. */
    public function requestedTotalMinor(): int
    {
        return Money::toMinor((string) $this->requested_amount) + Money::toMinor((string) ($this->requested_interest_amount ?? 0));
    }

    /** ADR-230 — les pénalités de retard encore dues : les pénalités remises ne comptent pas. */
    public function penaltiesMinor(): int
    {
        $penalties = $this->relationLoaded('penalties')
            ? $this->penalties->whereNull('waived_at')
            : $this->penalties()->whereNull('waived_at')->get(['amount']);

        return (int) $penalties->sum(fn (StaffDebtPenalty $penalty) => Money::toMinor((string) $penalty->amount));
    }

    /** Le montant et son intérêt, moins ce qui en a été remis : ce que les mensualités doivent couvrir, pénalités à part. */
    public function principalOwedMinor(): int
    {
        return max(0, $this->totalDueMinor() - Money::toMinor((string) ($this->written_off_amount ?? 0)));
    }

    /** Le reste dû, en unités mineures ; zéro tant que rien n'est accordé. */
    public function balanceMinor(): int
    {
        if ($this->amount === null) {
            return 0;
        }

        return max(0, $this->totalDueMinor() + $this->penaltiesMinor() - $this->repaidMinor() - Money::toMinor((string) ($this->written_off_amount ?? 0)));
    }

    /** ADR-230 — la règle de pénalité figée à l'accord ; null quand aucune ne s'applique. @return array{rate: string, grace_days: int, cap_rate: ?string}|null */
    public function penaltyRule(): ?array
    {
        if ($this->penalty_rate === null || (float) $this->penalty_rate <= 0) {
            return null;
        }

        return [
            'rate' => (string) $this->penalty_rate,
            'grace_days' => (int) ($this->penalty_grace_days ?? 0),
            'cap_rate' => $this->penalty_cap_rate !== null ? (string) $this->penalty_cap_rate : null,
        ];
    }

    /**
     * ADR-230 — la personne a quitté la clinique (fiche inactive ou archivée) et le reste de sa
     * dette n'a pas encore été réglé avec le DG.
     */
    public function awaitsDepartureSettlement(): bool
    {
        if ($this->status !== StaffDebtStatus::Active || $this->departure_settled_at !== null) {
            return false;
        }

        $employee = $this->employee;

        return $employee === null || ! $employee->active || $employee->trashed();
    }

    /** Les dettes en remboursement d'une personne partie, pas encore réglées au départ. */
    public function scopeAwaitingDepartureSettlement(Builder $query): Builder
    {
        return $query->where('status', StaffDebtStatus::Active->value)
            ->whereNull('departure_settled_at')
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('employee')
                ->orWhereHas('employee', fn (Builder $employee) => $employee->where('active', false)->orWhereNotNull('deleted_at')));
    }

    /** Les dettes en remboursement ordinaires : pas en attente d'un règlement au départ. */
    public function scopeRepayingNormally(Builder $query): Builder
    {
        return $query->where('status', StaffDebtStatus::Active->value)
            ->where(fn (Builder $query) => $query
                ->whereNotNull('departure_settled_at')
                ->orWhereHas('employee', fn (Builder $employee) => $employee->where('active', true)->whereNull('deleted_at')));
    }
}
