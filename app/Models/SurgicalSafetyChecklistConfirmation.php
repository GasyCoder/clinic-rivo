<?php

namespace App\Models;

use App\Enums\SurgicalChecklistRole;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-170 — la confirmation d'un rôle sur un temps de la checklist : qui, et
 * quand. Jamais un booléen anonyme.
 */
#[Fillable([
    'surgical_safety_checklist_id', 'role', 'confirmed_by', 'confirmed_at',
])]
class SurgicalSafetyChecklistConfirmation extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'role' => SurgicalChecklistRole::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(SurgicalSafetyChecklist::class, 'surgical_safety_checklist_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
