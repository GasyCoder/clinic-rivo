<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-144 — relie le patient « nouveau-né » à sa mère et au bébé consigné en Maternité.
 *
 * Un lien n'est jamais supprimé : il atteste d'où vient ce dossier. La donnée clinique de la
 * naissance (poids, Apgar, soins) reste dans `maternity_records.newborn_data` — le lien la désigne,
 * il ne la recopie pas.
 */
#[Fillable(['patient_id', 'mother_patient_id', 'maternity_record_id', 'newborn_uuid', 'birth_rank', 'created_by'])]
class PatientNewbornLink extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return ['birth_rank' => 'integer'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function mother(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'mother_patient_id');
    }

    public function maternityRecord(): BelongsTo
    {
        return $this->belongsTo(MaternityRecord::class);
    }

    protected function auditModule(): ?string
    {
        return 'maternity';
    }
}
