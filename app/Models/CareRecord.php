<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Audited nursing worksheet for one episode. Hospitalization and diagnostic
 * fields are nursing context only: they never change the episode's medical
 * status and never replace a physician-owned Diagnosis record.
 */
#[Fillable([
    'episode_id', 'blood_group', 'height_cm', 'weight_kg', 'bmi',
    'allergy_note', 'smoker', 'hospitalization_reason', 'hospitalized_at',
    'discharged_at', 'diagnostic_note', 'transmission_reason',
    'created_by', 'updated_by',
])]
class CareRecord extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'height_cm' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'bmi' => 'decimal:2',
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
