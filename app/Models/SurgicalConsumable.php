<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (see migration) — surgery.consumables.create only. Records
 * what was used for billing/traceability; never touches Pharmacy stock
 * (ADR-013, module isolation).
 */
#[Fillable(['surgical_request_id', 'label', 'quantity', 'unit', 'recorded_by'])]
class SurgicalConsumable extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
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
