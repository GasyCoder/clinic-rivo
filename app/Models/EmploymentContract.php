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
    'employee_id', 'contract_type_id', 'reference_number', 'signed_on',
    'starts_on', 'trial_ends_on', 'ends_on', 'observation',
])]
class EmploymentContract extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'signed_on' => 'date',
            'starts_on' => 'date',
            'trial_ends_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'contract_type_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HrDocument::class);
    }

    public function isForceDeleteProtected(): bool
    {
        return true;
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
