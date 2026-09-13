<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A treatment the patient is already taking when seen — the "TRAITEMENTS
 * ACTUELS" block of the clinic's DOSSIER MÉDICAL.
 *
 * Declarative only: it never references a Pharmacy product, never reserves
 * stock and carries no price. Prescribing is a separate act with its own
 * workflow (ADR-036/037); this records what the patient reports taking,
 * possibly bought elsewhere.
 */
#[Fillable([
    'consultation_id', 'medication_name', 'dosage', 'frequency', 'duration',
    'source', 'notes', 'position', 'recorded_by',
])]
class ConsultationCurrentTreatment extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
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
