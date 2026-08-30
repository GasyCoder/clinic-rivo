<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'created_by', 'updated_by'])]
class MedicineCategory extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
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
        return $this->medicines()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'pharmacy';
    }
}
