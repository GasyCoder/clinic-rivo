<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Which lot served which declared line, through which immutable stock
 * movement. Append-only, exactly like PharmacyDispenseAllocation.
 */
#[Fillable([
    'care_consumable_request_line_id', 'medicine_lot_id',
    'pharmacy_stock_movement_id', 'quantity', 'served_at', 'served_by',
])]
class CareConsumableAllocation extends Model
{
    use HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une allocation de consommables Soins ne peut pas être supprimée.'));
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'served_at' => 'datetime',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CareConsumableRequestLine::class, 'care_consumable_request_line_id');
    }

    public function medicineLot(): BelongsTo
    {
        return $this->belongsTo(MedicineLot::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(PharmacyStockMovement::class, 'pharmacy_stock_movement_id');
    }

    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }
}
