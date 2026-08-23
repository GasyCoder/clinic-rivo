<?php

namespace App\Models;

use App\Enums\MedicineForm;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'catalog_item_id', 'generic_name', 'form', 'strength', 'active',
    'created_by', 'updated_by',
])]
class Medicine extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'form' => MedicineForm::class,
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
