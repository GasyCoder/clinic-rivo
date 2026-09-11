<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_template_id', 'template_name_snapshot', 'document_type_snapshot',
    'employee_id', 'employment_contract_id', 'leave_request_id',
    'resolved_variables_snapshot', 'manual_variables_snapshot', 'rendered_html_snapshot',
    'generated_by',
])]
class GeneratedDocument extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'resolved_variables_snapshot' => 'array',
            'manual_variables_snapshot' => 'array',
        ];
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class)->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function employmentContract(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class)->withTrashed();
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
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
