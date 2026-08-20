<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ProtectsFinancialRecord;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'catalog_item_id', 'amount', 'currency', 'effective_from', 'effective_until',
    'active_key', 'change_reason', 'created_by', 'ended_by',
])]
class CatalogTariff extends Model
{
    use Auditable, HasUuid, ProtectsFinancialRecord;

    protected function casts(): array
    {
        return [
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

    protected function auditModule(): ?string
    {
        return 'catalog';
    }
}
