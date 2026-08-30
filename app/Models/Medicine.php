<?php

namespace App\Models;

use App\Enums\MedicineForm;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'catalog_item_id', 'medicine_category_id', 'generic_name', 'form', 'strength',
    'manufacturer', 'barcode', 'minimum_stock', 'prescription_required', 'active',
    'created_by', 'updated_by',
])]
class Medicine extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'form' => MedicineForm::class,
            'minimum_stock' => 'integer',
            'prescription_required' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(MedicineLot::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MedicineCategory::class, 'medicine_category_id');
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(MedicineSupplier::class, 'medicine_supplier')->withTimestamps();
    }

    public function stockAlerts(): HasMany
    {
        return $this->hasMany(PharmacyStockAlert::class);
    }

    public function dispenseLines(): HasMany
    {
        return $this->hasMany(PharmacyDispenseLine::class);
    }

    public function prescriptionLines(): HasMany
    {
        return $this->hasMany(PrescriptionLine::class);
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->lots()->exists()
            || $this->dispenseLines()->exists()
            || $this->prescriptionLines()->exists()
            || $this->stockAlerts()->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
