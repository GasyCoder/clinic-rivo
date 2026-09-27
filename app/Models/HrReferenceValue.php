<?php

namespace App\Models;

use App\Enums\HrReferenceType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['type', 'code', 'label', 'active', 'position', 'metadata'])]
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
            'metadata' => 'array',
        ];
    }

    public function scopeOfType(Builder $query, HrReferenceType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * ADR-194 — une fonction liste les départements où elle existe. Sans
     * aucun lien, elle reste proposée partout.
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'hr_job_title_departments', 'job_title_id', 'department_id')
            ->withTimestamps();
    }

    /** Les fonctions reliées à ce département. */
    public function departmentJobTitles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'hr_job_title_departments', 'department_id', 'job_title_id')
            ->withTimestamps();
    }

    public function internshipContracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'internship_field_id');
    }

    /** ADR-194 — un type de contrat marqué « contrat de stage » dans Paramètres RH. */
    public function isInternshipContractType(): bool
    {
        return $this->type === HrReferenceType::ContractType
            && (bool) ($this->metadata['internship'] ?? false);
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

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_type_id');
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
            || $this->leaveRequests()->exists()
            || $this->attestationDocuments()->withTrashed()->exists()
            || $this->internshipContracts()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'administration';
    }
}
