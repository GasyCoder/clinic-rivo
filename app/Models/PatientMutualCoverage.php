<?php

namespace App\Models;

use App\Enums\MutualBeneficiaryType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'patient_id', 'mutual_organization_id', 'employer_name',
    'beneficiary_type', 'membership_number', 'active_key', 'created_by',
    'effective_from', 'ended_by', 'effective_until', 'end_reason',
])]
class PatientMutualCoverage extends Model
{
    use Auditable, HasUuid;

    protected static function booted(): void
    {
        static::saving(function (self $coverage): void {
            $coverage->active_key = $coverage->effective_until === null
                ? "patient:{$coverage->patient_id}"
                : null;
            $coverage->effective_from ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'beneficiary_type' => MutualBeneficiaryType::class,
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class)->withTrashed();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(MutualOrganization::class, 'mutual_organization_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PatientMutualCoverageAttachment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('effective_until');
    }

    public function isActive(): bool
    {
        return $this->effective_until === null;
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
