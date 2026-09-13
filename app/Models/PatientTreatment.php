<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A treatment explicitly promoted to the patient's permanent medical
 * record. It is declarative only: no Pharmacy product, stock or price is
 * attached to it, and a Consultation never updates it silently.
 */
#[Fillable([
    'patient_id', 'medication_name', 'dosage', 'frequency', 'duration',
    'notes', 'active', 'recorded_by',
])]
class PatientTreatment extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
