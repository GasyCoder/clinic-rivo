<?php

namespace App\Models;

use App\Enums\StaffBlockCreditMovementType;
use App\Models\Builders\ImmutableStaffBlockCreditMovementBuilder;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'employee_id', 'amount', 'movement_type', 'episode_id', 'billable_item_id',
    'reversal_of_id', 'balance_before', 'balance_after', 'idempotency_key',
    'reason', 'created_by',
])]
class StaffBlockCreditMovement extends Model
{
    use Auditable, HasUuid;

    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $type = $movement->movement_type instanceof StaffBlockCreditMovementType
                ? $movement->movement_type
                : StaffBlockCreditMovementType::from((string) $movement->movement_type);
            $amountMinor = Money::toMinor($movement->amount);
            $beforeMinor = Money::toMinor($movement->balance_before);
            $afterMinor = Money::toMinor($movement->balance_after);

            if ($beforeMinor < 0 || $afterMinor < 0 || $afterMinor !== $beforeMinor + $amountMinor) {
                throw new LogicException('Les soldes du mouvement de crédit Bloc sont incohérents.');
            }

            if ($type === StaffBlockCreditMovementType::Allocation
                && ($amountMinor <= 0 || $movement->episode_id || $movement->billable_item_id || $movement->reversal_of_id)) {
                throw new LogicException('Une allocation Bloc doit être positive et indépendante d’une prestation.');
            }

            if ($type === StaffBlockCreditMovementType::Consumption
                && ($amountMinor >= 0 || ! $movement->episode_id || ! $movement->billable_item_id || $movement->reversal_of_id)) {
                throw new LogicException('Une consommation Bloc doit être négative et référencer son Episode et sa prestation.');
            }

            if ($type === StaffBlockCreditMovementType::Reversal
                && ($amountMinor <= 0 || ! $movement->episode_id || ! $movement->billable_item_id || ! $movement->reversal_of_id)) {
                throw new LogicException('Une réversion Bloc doit être positive et référencer la consommation annulée.');
            }
        });
        static::updating(fn () => throw new LogicException('Un mouvement de crédit Bloc ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Un mouvement de crédit Bloc ne peut pas être supprimé.'));
    }

    protected function casts(): array
    {
        return [
            'movement_type' => StaffBlockCreditMovementType::class,
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function newEloquentBuilder($query): ImmutableStaffBlockCreditMovementBuilder
    {
        return new ImmutableStaffBlockCreditMovementBuilder($query);
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
