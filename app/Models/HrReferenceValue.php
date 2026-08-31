<?php

namespace App\Models;

use App\Enums\HrReferenceType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['type', 'code', 'label', 'active', 'position'])]
class HrReferenceValue extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected static function booted(): void
    {
        static::saving(function (self $reference): void {
            $reference->label = Str::squish($reference->label);
            $reference->code = Str::of($reference->code ?: $reference->label)
                ->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '_')->trim('_')->toString();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => HrReferenceType::class,
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function scopeOfType(Builder $query, HrReferenceType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function departmentEmployees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }

    public function jobTitleEmployees(): HasMany
    {
        return $this->hasMany(Employee::class, 'job_title_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'contract_type_id');
    }

    public function planningShifts(): HasMany
    {
        return $this->hasMany(PlanningShift::class, 'department_id');
    }

    public function attestationDocuments(): HasMany
    {
        return $this->hasMany(HrDocument::class, 'attestation_type_id');
    }

    public function isForceDeleteProtected(): bool
    {
        return $this->departmentEmployees()->withTrashed()->exists()
            || $this->jobTitleEmployees()->withTrashed()->exists()
            || $this->contracts()->withTrashed()->exists()
            || $this->planningShifts()->exists()
            || $this->attestationDocuments()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
