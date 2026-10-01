<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ADR-213 — l'antibiogramme d'un germe identifié en culture. */
#[Fillable(['lab_request_item_id', 'analysis_catalog_id', 'bacterium_id', 'bacterium_name_snapshot', 'notes'])]
class LabAntibiogram extends Model
{
    use Auditable, HasUuid;

    public function item(): BelongsTo
    {
        return $this->belongsTo(LabRequestItem::class, 'lab_request_item_id');
    }

    public function bacterium(): BelongsTo
    {
        return $this->belongsTo(LabBacterium::class, 'bacterium_id')->withTrashed();
    }

    public function results(): HasMany
    {
        return $this->hasMany(LabAntibiogramResult::class)->orderBy('antibiotic_name_snapshot');
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
