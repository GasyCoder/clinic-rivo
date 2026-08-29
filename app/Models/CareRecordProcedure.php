<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only snapshot of an act actually performed by the care team. */
#[Fillable([
    'care_record_id', 'catalog_item_id', 'catalog_item_uuid', 'care_order_item_id',
    'procedure_code', 'procedure_name', 'quantity', 'notes',
    'allergy_checked_at', 'performed_by', 'performed_at',
])]
class CareRecordProcedure extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'allergy_checked_at' => 'datetime',
            'performed_at' => 'datetime',
        ];
    }

    public function careRecord(): BelongsTo
    {
        return $this->belongsTo(CareRecord::class);
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function careOrderItem(): BelongsTo
    {
        return $this->belongsTo(CareOrderItem::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    protected function auditModule(): ?string
    {
        return 'care';
    }
}
