<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ADR-230 — une pénalité de retard liquidée sur une dette du personnel remboursée en
 * espèces : pour un mois, le taux de la dette appliqué au seul montant encore en retard à
 * la fin du délai de grâce. Une par mois au plus. Jamais supprimée : le DG la remet, avec
 * un motif.
 */
#[Fillable([
    'staff_debt_id', 'period', 'base_amount', 'rate', 'amount', 'assessed_at',
    'waived_at', 'waived_by', 'external_waived_by_uuid', 'external_waived_by_name', 'waiver_reason',
])]
class StaffDebtPenalty extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une pénalité ne se supprime pas : elle se remet, avec un motif.'));
    }

    protected function auditModule(): ?string
    {
        return 'finance';
    }

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'assessed_at' => 'datetime',
            'waived_at' => 'datetime',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(StaffDebt::class, 'staff_debt_id');
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }
}
