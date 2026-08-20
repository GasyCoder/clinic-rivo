<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalConsumable;
use App\Services\Audit\Auditor;

/**
 * No dedicated CDC permission exists for removing a consumable (§15/16
 * lists only surgery.consumables.create) — gated behind that same
 * permission rather than the unrelated generic surgery.update, since it's
 * specifically consumables work. A real delete (no SoftDeletable — this
 * isn't critical/financial data), audited explicitly since Auditable only
 * covers create/update.
 */
class RemoveSurgicalConsumableAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(SurgicalConsumable $consumable): void
    {
        $this->auditor->record('delete', entity: $consumable, module: 'surgery');

        $consumable->delete();
    }
}
