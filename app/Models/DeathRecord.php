<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'acte de constatation de décès (ADR-107).
 *
 * Distinct de la sortie médicale : celle-ci est la décision clinique qui
 * prononce le décès (ADR-035), celui-ci est le document que le médecin signe
 * et que la famille emporte. Un passage n'en porte qu'un ; sa correction
 * relève d'un mécanisme tracé, jamais d'un second acte (ADR-010).
 */
#[Fillable([
    'episode_id', 'patient_id', 'medical_discharge_id',
    'death_occurred_at', 'death_place', 'death_causes', 'observations',
    'constated_at', 'constated_by',
])]
class DeathRecord extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'death_occurred_at' => 'datetime',
            'constated_at' => 'datetime',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function medicalDischarge(): BelongsTo
    {
        return $this->belongsTo(MedicalDischarge::class);
    }

    public function constatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'constated_by');
    }
}
