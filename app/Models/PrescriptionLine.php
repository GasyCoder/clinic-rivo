<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `medication_name` is free text — no medicines catalog exists yet
 * (Pharmacie, Phase 4). Reconciling with a real catalog belongs to that
 * future module.
 */
#[Fillable(['prescription_id', 'medication_name', 'dosage', 'frequency', 'duration', 'instructions'])]
class PrescriptionLine extends Model
{
    use Auditable;

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
