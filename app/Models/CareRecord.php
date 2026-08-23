<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Audited nursing worksheet for one episode. The legacy hospitalization date
 * columns are retained for historical compatibility but are no longer writable
 * from Care: admission and discharge belong to physician-owned workflows.
 */
#[Fillable([
    'episode_id', 'blood_group',
    'blood_pressure_left_systolic', 'blood_pressure_left_diastolic',
    'blood_pressure_right_systolic', 'blood_pressure_right_diastolic',
    'temperature_celsius', 'known_diabetes',
    'height_cm', 'weight_kg', 'bmi',
    'allergy_note', 'allergy_snapshot', 'smoker', 'no_procedure_reason',
    'hospitalization_reason', 'hospitalized_at',
    'discharged_at', 'diagnostic_note', 'transmission_reason',
    'created_by', 'updated_by',
])]
class CareRecord extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'blood_pressure_left_systolic' => 'integer',
            'blood_pressure_left_diastolic' => 'integer',
            'blood_pressure_right_systolic' => 'integer',
            'blood_pressure_right_diastolic' => 'integer',
            'temperature_celsius' => 'decimal:2',
            'known_diabetes' => 'boolean',
            'height_cm' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'bmi' => 'decimal:2',
            'allergy_snapshot' => 'array',
            'smoker' => 'boolean',
            'hospitalized_at' => 'datetime',
            'discharged_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(CareRecordProcedure::class)->latest('performed_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function auditModule(): ?string
    {
        return 'care';
    }
}
