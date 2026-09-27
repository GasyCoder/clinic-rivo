<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_catalog_id', 'reference', 'medicine_label', 'presentation', 'family_label',
    'supplier_price', 'row_number', 'linked_medicine_id', 'created_by',
])]
class SupplierCatalogItem extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'supplier_price' => 'decimal:2',
            'row_number' => 'integer',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(SupplierCatalog::class, 'supplier_catalog_id');
    }

    public function linkedMedicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class, 'linked_medicine_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(MedicineSupplierOffer::class, 'supplier_catalog_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLinked(): bool
    {
        return $this->linked_medicine_id !== null;
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
