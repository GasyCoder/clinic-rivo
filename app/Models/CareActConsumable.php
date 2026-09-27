<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-072 — the material usually consumed by a nursing act. A data-entry
 * suggestion only: it never creates a stock movement or a charge by itself,
 * and the nurse always confirms what was really used.
 *
 * Unlike a declared consumption, this is configuration: it may be corrected
 * and removed freely by whoever administers the catalogue.
 */
#[Fillable([
    'catalog_item_id', 'medicine_id', 'default_quantity', 'position',
    'created_by', 'updated_by',
])]
class CareActConsumable extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'default_quantity' => 'integer',
            'position' => 'integer',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
