<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'contact_name', 'phone', 'email', 'address',
    'created_by', 'updated_by',
])]
class MedicineSupplier extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    public function medicines(): BelongsToMany
    {
        return $this->belongsToMany(Medicine::class, 'medicine_supplier')->withTimestamps();
    }

    public function lots(): HasMany
    {
        return $this->hasMany(MedicineLot::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(PharmacyStockMovement::class);
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
        return $this->medicines()->exists()
            || $this->lots()->exists()
            || $this->stockMovements()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
