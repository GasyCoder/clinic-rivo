<?php

namespace App\Models;

use App\Enums\MedicineStockReservationStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'medicine_id', 'medicine_supplier_id', 'lot_number', 'received_at', 'expires_at',
    'quantity_on_hand', 'active', 'created_by', 'updated_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_updated_by_uuid', 'external_updated_by_name',
])]
class MedicineLot extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'expires_at' => 'date',
            'quantity_on_hand' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(MedicineStockReservation::class);
    }

    public function counterReservations(): HasMany
    {
        return $this->hasMany(PharmacyDispenseLotReservation::class);
    }

    public function activeReservations(): HasMany
    {
        return $this->reservations()
            ->where('status', MedicineStockReservationStatus::Reserved->value);
    }

    /**
     * ADR-098 — the single definition of what a lot still holds for others:
     * quantities reserved for a prescription or for a pending dispense.
     * Stock screens, the Medicine catalog and stock alerts all read it here
     * instead of each summing reservations their own way.
     */
    public function scopeWithReservedQuantity(Builder $query): Builder
    {
        $stillReserved = fn ($reservations) => $reservations->where('status', MedicineStockReservationStatus::Reserved->value);

        return $query
            ->withSum(['reservations as prescription_reserved_quantity' => $stillReserved], 'remaining_quantity')
            ->withSum(['counterReservations as counter_reserved_quantity' => $stillReserved], 'remaining_quantity');
    }

    /** Requires the lot to have been loaded through withReservedQuantity(). */
    public function reservedQuantity(): int
    {
        return (int) ($this->prescription_reserved_quantity ?? 0) + (int) ($this->counter_reserved_quantity ?? 0);
    }

    /**
     * What this lot can still serve: its physical quantity minus what is
     * already held for someone else. Same single definition as
     * `reservedQuantity()` (ADR-098), so no screen invents its own subtraction.
     */
    public function availableQuantity(): int
    {
        return max(0, $this->quantity_on_hand - $this->reservedQuantity());
    }

    /** An expired lot is never available, whatever it still physically holds (ADR-036). */
    public function isUsableOn(CarbonImmutable $day): bool
    {
        return $this->active && $this->expires_at !== null && $this->expires_at->gte($day);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(PharmacyStockMovement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplier::class, 'medicine_supplier_id');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
