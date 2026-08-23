<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'surgical_request_id', 'height_cm', 'weight_kg', 'temperature_celsius',
    'blood_pressure_systolic', 'blood_pressure_diastolic', 'heart_rate', 'oxygen_saturation',
    'full_bath_completed', 'weighing_completed', 'peripheral_iv_count',
    'serum_name', 'serum_quantity', 'serum_unit', 'urinary_catheter_placed',
    'diuresis_quantity', 'diuresis_unit', 'urine_appearance', 'catheter_placed_at',
    'created_by', 'updated_by',
])]
class SurgicalBlockEntry extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'height_cm' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'temperature_celsius' => 'decimal:2',
            'blood_pressure_systolic' => 'integer',
            'blood_pressure_diastolic' => 'integer',
            'heart_rate' => 'integer',
            'oxygen_saturation' => 'integer',
            'full_bath_completed' => 'boolean',
            'weighing_completed' => 'boolean',
            'peripheral_iv_count' => 'integer',
            'serum_quantity' => 'decimal:2',
            'urinary_catheter_placed' => 'boolean',
            'diuresis_quantity' => 'decimal:2',
            'catheter_placed_at' => 'datetime',
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
