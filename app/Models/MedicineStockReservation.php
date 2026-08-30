<?php

namespace App\Models;

use App\Enums\MedicineStockReservationStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'prescription_line_id', 'medicine_lot_id', 'quantity', 'remaining_quantity', 'status',
    'reserved_at', 'reserved_by', 'released_at', 'released_by',
    'release_reason', 'dispensed_at', 'dispensed_by',
])]
class MedicineStockReservation extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::creating(function (self $reservation) {
            if ($reservation->status === null || $reservation->status === MedicineStockReservationStatus::Reserved) {
                $reservation->remaining_quantity ??= $reservation->quantity;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'remaining_quantity' => 'integer',
            'status' => MedicineStockReservationStatus::class,
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
            'dispensed_at' => 'datetime',
        ];
    }

    public function prescriptionLine(): BelongsTo
    {
        return $this->belongsTo(PrescriptionLine::class);
    }

    public function medicineLot(): BelongsTo
    {
        return $this->belongsTo(MedicineLot::class);
    }

    public function reserver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function dispenser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
