<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One requested exam (ECG, échographie…), snapshotting the catalog at request time. */
#[Fillable([
    'imaging_request_id', 'catalog_item_id', 'billable_item_id', 'catalog_item_code_snapshot', 'catalog_item_name_snapshot',
    'result_value', 'result_notes', 'resulted_at', 'resulted_by',
])]
class ImagingRequestItem extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return ['resulted_at' => 'datetime'];
    }

    public function imagingRequest(): BelongsTo
    {
        return $this->belongsTo(ImagingRequest::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function resultedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resulted_by');
    }

    protected function auditModule(): ?string
    {
        return 'clinical_flow';
    }

    /** ADR-105 — la prestation portée au compte du patient pour cet examen. */
    public function billableItem(): BelongsTo
    {
        return $this->belongsTo(BillableItem::class);
    }
}
