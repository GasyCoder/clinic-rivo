<?php

namespace App\Models;

use App\Enums\MedicineStockReservationStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pharmacy_dispense_line_id', 'medicine_lot_id', 'quantity',
    'remaining_quantity', 'status', 'reserved_at', 'reserved_by',
    'released_at', 'released_by', 'release_reason', 'dispensed_at', 'dispensed_by',
])]
class PharmacyDispenseLotReservation extends Model
{
    use Auditable, HasUuid;

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

    public function dispenseLine(): BelongsTo
    {
        return $this->belongsTo(PharmacyDispenseLine::class, 'pharmacy_dispense_line_id');
    }

    public function medicineLot(): BelongsTo
    {
        return $this->belongsTo(MedicineLot::class);
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
