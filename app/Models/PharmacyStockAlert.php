<?php

namespace App\Models;

use App\Enums\PharmacyStockAlertType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medicine_id', 'type', 'status', 'active_key', 'available_quantity',
    'threshold', 'triggered_at', 'resolved_at',
])]
class PharmacyStockAlert extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'type' => PharmacyStockAlertType::class,
            'available_quantity' => 'integer',
            'threshold' => 'integer',
            'triggered_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
