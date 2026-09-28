<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-213 — le résultat d'une analyse du catalogue pour une analyse demandée.
 * Désignation, mode de saisie, unité et référence sont figés à la saisie : le
 * catalogue corrigé plus tard ne réécrit jamais un résultat.
 */
#[Fillable([
    'lab_request_item_id', 'analysis_catalog_id', 'designation_snapshot', 'entry_mode', 'unit_snapshot',
    'reference_snapshot', 'value', 'selections', 'interpretation', 'range_flag',
    'is_critical', 'critical_flagged_at', 'critical_flagged_by', 'entered_by',
])]
class LabResult extends Model
{
    use Auditable, HasUuid;

    public const INTERPRETATIONS = ['NORMAL', 'PATHOLOGICAL'];

    protected function casts(): array
    {
        return [
            'selections' => 'array',
            'is_critical' => 'boolean',
            'critical_flagged_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LabRequestItem::class, 'lab_request_item_id');
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(AnalysisCatalog::class, 'analysis_catalog_id')->withTrashed();
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** Rien n'est saisi : ni valeur, ni choix. */
    public function isBlank(): bool
    {
        return blank($this->value) && empty($this->selections);
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
