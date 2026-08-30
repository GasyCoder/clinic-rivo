<?php

namespace App\Models;

use App\Enums\PharmacyStockMovementType;
use App\Models\Builders\ImmutableStockMovementBuilder;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'medicine_lot_id', 'medicine_supplier_id', 'type', 'quantity_delta', 'balance_after',
    'unit_purchase_price',
    'source_key', 'origin', 'destination', 'reason', 'occurred_at', 'performed_by',
    'external_actor_uuid', 'external_actor_name',
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
            'unit_purchase_price' => 'decimal:2',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplier::class, 'medicine_supplier_id');
    }

    public function newEloquentBuilder($query): ImmutableStockMovementBuilder
    {
        return new ImmutableStockMovementBuilder($query);
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
