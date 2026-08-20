<?php

namespace App\Models;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'code', 'name', 'type', 'module', 'unit', 'billable', 'stockable',
    'description', 'created_by', 'updated_by',
])]
class CatalogItem extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'type' => CatalogItemType::class,
            'module' => CatalogModule::class,
            'billable' => 'boolean',
            'stockable' => 'boolean',
        ];
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(CatalogTariff::class)->latest('effective_from');
    }

    public function currentTariff(): HasOne
    {
        return $this->hasOne(CatalogTariff::class)->where('active_key', 'CURRENT');
    }

    public function billableItems(): HasMany
    {
        return $this->hasMany(BillableItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->tariffs()->exists() || $this->billableItems()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'catalog';
    }
}
