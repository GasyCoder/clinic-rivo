<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CDC GitHub §15/16 — surgery.report.create/update/validate. Once
 * validated, this row *is* CDC §11's "acte chirurgical validé" — but §15/16
 * lists no surgery.report.delete/restore/force_delete permission at all, so
 * there is nothing to gate a SoftDeletable/force_delete-protection
 * mechanism behind. This is the clearest instance of the §11 vs §15/16
 * conflict in this module (see surgical_requests migration) and is
 * deliberately left unresolved pending clarification rather than inventing
 * a permission the CDC does not list.
 */
#[Fillable(['surgical_request_id', 'authored_by', 'content', 'validated_by', 'validated_at'])]
class SurgicalReport extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    protected function auditModule(): ?string
    {
        return 'surgery';
    }
}
