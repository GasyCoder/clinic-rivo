<?php

namespace App\Models;

use App\Enums\PharmacyStockMovementType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'medicine_lot_id', 'type', 'quantity_delta', 'balance_after',
    'source_key', 'reason', 'occurred_at', 'performed_by',
])]
class PharmacyStockMovement extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un mouvement de stock validé ne peut pas être modifié.'));
        static::deleting(fn () => throw new LogicException('Un mouvement de stock validé ne peut pas être supprimé.'));
    }

    protected function casts(): array
    {
        return [
            'type' => PharmacyStockMovementType::class,
            'quantity_delta' => 'integer',
            'balance_after' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function medicineLot(): BelongsTo
    {
        return $this->belongsTo(MedicineLot::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
