<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'pharmacy_dispense_event_id', 'pharmacy_dispense_line_id',
    'medicine_lot_id', 'pharmacy_stock_movement_id', 'quantity',
])]
class PharmacyDispenseAllocation extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une allocation délivrée ne peut pas être modifiée.'));
        static::deleting(fn () => throw new LogicException('Une allocation délivrée ne peut pas être supprimée.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PharmacyDispenseEvent::class, 'pharmacy_dispense_event_id');
    }

    public function dispenseLine(): BelongsTo
    {
        return $this->belongsTo(PharmacyDispenseLine::class, 'pharmacy_dispense_line_id');
    }

    public function medicineLot(): BelongsTo
    {
        return $this->belongsTo(MedicineLot::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(PharmacyStockMovement::class, 'pharmacy_stock_movement_id');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
