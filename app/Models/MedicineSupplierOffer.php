<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'medicine_id', 'medicine_supplier_id', 'supplier_catalog_item_id', 'supplier_reference',
    'quoted_price', 'currency', 'effective_from', 'effective_until', 'active_key',
    'change_reason', 'created_by', 'ended_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_ended_by_uuid', 'external_ended_by_name',
])]
class MedicineSupplierOffer extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
            'quoted_price' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MedicineSupplier::class, 'medicine_supplier_id');
    }

    public function sourceCatalogItem(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalogItem::class, 'supplier_catalog_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function isCurrent(): bool
    {
        return $this->active_key === 'CURRENT' && $this->effective_until === null;
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
