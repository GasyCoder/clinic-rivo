<?php

namespace App\Models;

use App\Enums\DiagnosisType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Append-only (see migration) — a corrected diagnosis is a new FINAL row,
 * not an edit of the old one, so the clinical trail never silently changes
 * shape underneath an already-acted-upon HYPOTHESIS/FINAL entry.
 */
#[Fillable(['consultation_id', 'type', 'description', 'recorded_by'])]
class Diagnosis extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'type' => DiagnosisType::class,
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

    public function cancellation(): HasOne
    {
        return $this->hasOne(DiagnosisCancellation::class);
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
