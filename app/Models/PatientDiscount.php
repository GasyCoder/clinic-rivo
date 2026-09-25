<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ADR-192 — la remise propre à un patient précis : une décision d'une personne
 * habilitée (`discounts.approve`), avec son motif, son auteur et ses dates
 * (CDC §34.2 règle 7). Elle ne se supprime pas : elle s'annule, avec un motif.
 */
#[Fillable([
    'patient_id', 'discount_type', 'discount_value', 'reason', 'valid_from', 'valid_until',
    'created_by', 'cancelled_at', 'cancelled_by', 'cancel_reason',
])]
class PatientDiscount extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected function auditModule(): ?string
    {
        return 'billing';
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** En vigueur à une date : non annulée, commencée, pas encore terminée. */
    public function scopeInForce(Builder $query, ?Carbon $on = null): Builder
    {
        $today = ($on ?? now())->toDateString();

        return $query->whereNull('cancelled_at')
            ->whereDate('valid_from', '<=', $today)
            ->where(fn (Builder $inner) => $inner->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today));
    }

    public function isInForce(?Carbon $on = null): bool
    {
        $today = ($on ?? now())->toDateString();

        return $this->cancelled_at === null
            && $this->valid_from->toDateString() <= $today
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $today);
    }
}
