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
    'pharmacy_dispense_id', 'prescription_line_id', 'medicine_id',
    'billable_item_id', 'medicine_name', 'medicine_code', 'unit',
    'quantity_requested', 'quantity_dispensed',
])]
class PharmacyDispenseLine extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Une ligne de dispensation ne peut pas être supprimée.'));
    }

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_dispensed' => 'integer',
        ];
    }

    public function dispense(): BelongsTo
    {
        return $this->belongsTo(PharmacyDispense::class, 'pharmacy_dispense_id');
    }

    public function prescriptionLine(): BelongsTo
    {
        return $this->belongsTo(PrescriptionLine::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }

    public function counterReservations(): HasMany
    {
        return $this->hasMany(PharmacyDispenseLotReservation::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PharmacyDispenseAllocation::class);
    }

    public function remainingQuantity(): int
    {
        return max(0, $this->quantity_requested - $this->quantity_dispensed);
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
