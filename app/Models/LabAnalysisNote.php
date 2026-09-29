<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-218 — la note d'une ligne d'analyse, imprimée « Notes : » sous la ligne
 * sur le compte rendu. Une par ligne du catalogue et par analyse demandée.
 */
#[Fillable(['lab_request_item_id', 'analysis_catalog_id', 'note', 'written_by'])]
class LabAnalysisNote extends Model
{
    use Auditable, HasUuid;

    public const MAX_LENGTH = 1000;

    public function item(): BelongsTo
    {
        return $this->belongsTo(LabRequestItem::class, 'lab_request_item_id');
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(AnalysisCatalog::class, 'analysis_catalog_id')->withTrashed();
    }

    public function writtenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_by');
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
