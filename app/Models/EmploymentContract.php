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
    'internship_field_id', 'internship_school', 'internship_level', 'internship_supervisor_id',
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

    /** ADR-194 — la filière du stage, quand le contrat est un contrat de stage. */
    public function internshipField(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'internship_field_id')->withTrashed();
    }

    public function internshipSupervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'internship_supervisor_id')->withTrashed();
    }

    public function isInternship(): bool
    {
        return (bool) $this->contractType?->isInternshipContractType();
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
