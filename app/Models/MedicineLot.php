<?php

namespace App\Models;

use App\Enums\MedicineStockReservationStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
