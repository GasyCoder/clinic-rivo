<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * surgery.consumables.create — records what was used, for traceability.
 * Never touches Pharmacy stock (ADR-013, module isolation) and carries no
 * price: no tariff catalog exists anywhere (CDC or codebase). Billing goes
 * through the manual hand-off to Réception's invoice form instead (see
 * SurgeryController's billingHref) — Réception sets the price.
 *
 * No dedicated CDC permission for deletion either — gated behind
 * surgery.consumables.create itself (the closest matching permission,
 * same reasoning as team-member removal's use of surgery.update). A real
 * delete (no SoftDeletable — this isn't critical/financial data), audited
 * explicitly since Auditable only covers create/update.
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
