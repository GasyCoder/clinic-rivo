<?php

namespace App\Models;

use App\Enums\SalaryPaymentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * ADR-227 — la paie d'un employé pour un mois : salaire de base déclaré + avantages du
 * mois = brut (`total_amount`). ADR-228 — les retenues des dettes du personnel
 * (`deductions_amount`) s'en retranchent : à verser = brut − retenues. Aucune cotisation
 * ni impôt. Lignes figées au paiement ; le virement se fait hors RIVO. Jamais supprimée :
 * annulée avec un motif.
 */
#[Fillable([
    'employee_id', 'employee_name', 'period', 'remuneration_type', 'base_amount', 'advantages_amount',
    'deductions_amount', 'total_amount', 'lines', 'status', 'active_key',
    'paid_at', 'paid_by', 'external_paid_by_uuid', 'external_paid_by_name', 'payment_note',
])]
class SalaryPayment extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une paie ne se supprime pas : elle s’annule avec un motif.'));
    }

    public static function activeKey(int $employeeId, string $period): string
    {
        return "S-{$employeeId}-{$period}";
    }

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'lines' => 'array',
            'base_amount' => 'decimal:2',
            'advantages_amount' => 'decimal:2',
            'deductions_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => SalaryPaymentStatus::class,
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AdvantageEntry::class)->withTrashed();
    }

    public function debtRepayments(): HasMany
    {
        return $this->hasMany(StaffDebtRepayment::class);
    }

    /** Ce qui a été versé : le brut moins les retenues de dettes (ADR-228). */
    public function netAmount(): string
    {
        return number_format((float) $this->total_amount - (float) $this->deductions_amount, 2, '.', '');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
