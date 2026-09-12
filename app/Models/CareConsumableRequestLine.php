<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'care_consumable_request_id', 'medicine_id', 'billable_item_id',
    'medicine_name', 'medicine_code', 'unit', 'quantity_requested',
    'quantity_served',
])]
class CareConsumableRequestLine extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une ligne de consommables Soins ne peut pas être supprimée.'));
    }

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_served' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CareConsumableRequest::class, 'care_consumable_request_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CareConsumableAllocation::class, 'care_consumable_request_line_id');
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->quantity_requested - $this->quantity_served);
    }

    protected function auditModule(): ?string
    {
        return 'care';
    }
}
