<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'surgical_request_id', 'observed_at', 'diuresis_quantity', 'diuresis_unit',
    'temperature_celsius', 'blood_pressure_systolic', 'blood_pressure_diastolic',
    'heart_rate', 'oxygen_saturation', 'recorded_by',
])]
class SurgicalPostoperativeObservation extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'diuresis_quantity' => 'decimal:2',
            'temperature_celsius' => 'decimal:2',
            'blood_pressure_systolic' => 'integer',
            'blood_pressure_diastolic' => 'integer',
            'heart_rate' => 'integer',
            'oxygen_saturation' => 'integer',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
