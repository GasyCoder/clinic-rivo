<?php

namespace App\Models;

use App\Enums\SurgicalTreatmentCategory;
use App\Enums\SurgicalTreatmentPhase;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['surgical_request_id', 'phase', 'category', 'label', 'quantity', 'unit', 'recorded_by'])]
class SurgicalTreatmentItem extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'phase' => SurgicalTreatmentPhase::class,
            'category' => SurgicalTreatmentCategory::class,
            'quantity' => 'decimal:2',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
