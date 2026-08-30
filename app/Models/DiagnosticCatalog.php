<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'category', 'is_active'])]
class DiagnosticCatalog extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    protected function auditModule(): ?string
    {
        return 'medical';
    }
}
