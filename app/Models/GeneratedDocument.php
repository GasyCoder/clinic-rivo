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
    'employee_id', 'employment_contract_id', 'leave_request_id', 'replaces_document_id',
    'form_data_snapshot', 'rendered_html_snapshot',
    'generated_by',
])]
class GeneratedDocument extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'form_data_snapshot' => 'array',
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

    /** ADR-199 — la version que ce document remplace (archivée). */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id')->withTrashed();
    }

    /** La version qui a remplacé celui-ci, s'il y en a une. */
    public function replacedBy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'replaces_document_id')->withTrashed()->latestOfMany();
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
