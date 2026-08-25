<?php

namespace App\Models;

use App\Enums\CatalogTariffCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_item_id', 'tariff_category', 'amount', 'currency', 'effective_from', 'effective_until',
    'active_key', 'change_reason', 'created_by', 'ended_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_ended_by_uuid', 'external_ended_by_name',
])]
class CatalogTariff extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected $attributes = [
        'tariff_category' => CatalogTariffCategory::Standard->value,
    ];

    protected function casts(): array
    {
        return [
            'tariff_category' => CatalogTariffCategory::class,
            'amount' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
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

    public function isFor(CatalogTariffCategory $category): bool
    {
        return $this->tariff_category === $category;
    }

    protected function auditModule(): ?string
    {
        return 'catalog';
    }
}
