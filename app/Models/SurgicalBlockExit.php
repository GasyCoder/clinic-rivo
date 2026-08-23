<?php

namespace App\Models;

use App\Enums\SurgicalAwakeningStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'surgical_request_id', 'block_entered_at', 'block_exited_at',
    'entry_blood_pressure_systolic', 'entry_blood_pressure_diastolic', 'entry_heart_rate',
    'entry_oxygen_saturation', 'entry_respiratory_rate', 'entry_temperature_celsius',
    'exit_blood_pressure_systolic', 'exit_blood_pressure_diastolic', 'exit_heart_rate',
    'exit_oxygen_saturation', 'exit_respiratory_rate', 'exit_temperature_celsius',
    'perfusion_serum', 'perfusion_bag', 'transfusion_blood', 'transfusion_quantity',
    'transfusion_unit', 'urine_appearance', 'urine_quantity', 'urine_unit',
    'blood_loss_quantity', 'blood_loss_unit', 'drug_name', 'drug_quantity', 'drug_unit',
    'antibiotic_name', 'antibiotic_quantity', 'antibiotic_unit', 'awakening_status',
    'awakening_score', 'created_by', 'updated_by',
])]
class SurgicalBlockExit extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'block_entered_at' => 'datetime',
            'block_exited_at' => 'datetime',
            'entry_blood_pressure_systolic' => 'integer',
            'entry_blood_pressure_diastolic' => 'integer',
            'entry_heart_rate' => 'integer',
            'entry_oxygen_saturation' => 'integer',
            'entry_respiratory_rate' => 'integer',
            'entry_temperature_celsius' => 'decimal:2',
            'exit_blood_pressure_systolic' => 'integer',
            'exit_blood_pressure_diastolic' => 'integer',
            'exit_heart_rate' => 'integer',
            'exit_oxygen_saturation' => 'integer',
            'exit_respiratory_rate' => 'integer',
            'exit_temperature_celsius' => 'decimal:2',
            'transfusion_quantity' => 'decimal:2',
            'urine_quantity' => 'decimal:2',
            'blood_loss_quantity' => 'decimal:2',
            'drug_quantity' => 'decimal:2',
            'antibiotic_quantity' => 'decimal:2',
            'awakening_status' => SurgicalAwakeningStatus::class,
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
