<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client CDCF "dossier patient" + CDC §19 (inter-site transfer payload
 * lists "antécédents" explicitly). One row per known medical antecedent —
 * never overwritten, only soft-deleted with a reason if recorded in error,
 * so the clinical history stays fully traceable (ADR-010).
 */
#[Fillable(['patient_id', 'description', 'recorded_by'])]
class PatientAntecedent extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
