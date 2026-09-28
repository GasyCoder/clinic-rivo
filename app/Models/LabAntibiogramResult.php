<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-213 — une ligne d'antibiogramme : S (sensible), I (intermédiaire), R (résistant). */
#[Fillable(['lab_antibiogram_id', 'antibiotic_id', 'antibiotic_name_snapshot', 'interpretation', 'measure', 'measure_unit'])]
class LabAntibiogramResult extends Model
{
    use Auditable, HasUuid;

    public const INTERPRETATIONS = ['S', 'I', 'R'];

    protected function casts(): array
    {
        return ['measure' => 'decimal:2'];
    }

    public function antibiogram(): BelongsTo
    {
        return $this->belongsTo(LabAntibiogram::class, 'lab_antibiogram_id');
    }

    public function antibiotic(): BelongsTo
    {
        return $this->belongsTo(LabAntibiotic::class)->withTrashed();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
