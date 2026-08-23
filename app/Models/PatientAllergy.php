<?php

namespace App\Models;

use App\Enums\AllergySeverity;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client CDCF "dossier patient" + CDC §19 (inter-site transfer payload
 * lists "allergies" explicitly). Same historized shape as
 * PatientAntecedent — see that model for why this isn't a flat column.
 */
#[Fillable(['patient_id', 'allergen_reference_id', 'substance', 'reaction', 'severity', 'recorded_by'])]
class PatientAllergy extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'severity' => AllergySeverity::class,
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function reference(): BelongsTo
    {
        return $this->belongsTo(AllergenReference::class, 'allergen_reference_id');
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
