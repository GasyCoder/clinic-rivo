<?php

namespace App\Models;

use App\Enums\ClinicalExamSystem;
use App\Enums\ClinicalSystemStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the doctor found in one body system — or that they did not look.
 *
 * A row exists only because something was said about this system. Its
 * absence is reported as NOT_EXAMINED by
 * `ClinicalExamination::systems()`, never as normal.
 */
#[Fillable([
    'clinical_examination_id', 'system_code', 'status', 'findings', 'sort_order',
])]
class ClinicalExaminationFinding extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'system_code' => ClinicalExamSystem::class,
            'status' => ClinicalSystemStatus::class,
        ];
    }

    public function examination(): BelongsTo
    {
        return $this->belongsTo(ClinicalExamination::class, 'clinical_examination_id');
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
