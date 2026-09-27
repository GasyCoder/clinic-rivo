<?php

namespace App\Models;

use App\Enums\HrDocumentCategory;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'employment_contract_id', 'leave_request_id',
    'attestation_type_id', 'category', 'title', 'original_name', 'path',
    'mime_type', 'size', 'issued_on', 'notes', 'uploaded_by',
])]
class HrDocument extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'category' => HrDocumentCategory::class,
            'issued_on' => 'date',
            'size' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employmentContract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function attestationType(): BelongsTo
    {
        return $this->belongsTo(HrReferenceValue::class, 'attestation_type_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
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
