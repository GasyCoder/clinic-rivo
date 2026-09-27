<?php

namespace App\Models;

use App\Enums\BonusAwardStatus;
use App\Enums\BonusMeasure;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * ADR-212 — un bonus atteint : validé par les RH, puis marqué versé (le
 * versement se fait hors RIVO, ADR-066/206). Nombre de patients, seuil et
 * montant sont figés à la validation. Une attribution n'est jamais supprimée :
 * une erreur se corrige par une annulation motivée, tant qu'elle n'est pas versée.
 */
#[Fillable([
    'bonus_category_id', 'employee_id', 'period', 'category_name', 'measure',
    'patients_count', 'threshold', 'amount', 'counted_patients', 'status', 'active_key',
    'validated_at', 'validated_by', 'external_validated_by_uuid', 'external_validated_by_name',
])]
class BonusAward extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Un bonus ne se supprime pas : il s’annule avec un motif.'));
    }

    public static function activeKey(int $categoryId, int $employeeId, string $period): string
    {
        return "C{$categoryId}-E{$employeeId}-{$period}";
    }

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'measure' => BonusMeasure::class,
            'status' => BonusAwardStatus::class,
            'patients_count' => 'integer',
            'threshold' => 'integer',
            'amount' => 'decimal:2',
            'counted_patients' => 'array',
            'validated_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BonusCategory::class, 'bonus_category_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
