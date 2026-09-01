<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'maternity_record_id', 'catalog_item_id', 'catalog_item_uuid', 'procedure_code',
    'procedure_name', 'quantity', 'notes', 'performed_by', 'performed_at',
])]
class MaternityProcedure extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'performed_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(MaternityRecord::class, 'maternity_record_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    protected function auditModule(): ?string
    {
        return 'maternity';
    }
}
