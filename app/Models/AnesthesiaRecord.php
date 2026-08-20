<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CDC GitHub §15/16 — anesthesia.view/create/update/validate. No delete/
 * restore/force_delete permission is listed for this resource, so — like
 * SurgicalRequest — it has no SoftDeletable/deletion capability at all,
 * despite §11's generic rule implying validated clinical data should be
 * protected from destruction. Same flagged conflict as SurgicalRequest;
 * not resolved silently here.
 */
#[Fillable(['surgical_request_id', 'anesthetist_id', 'notes', 'administered_at', 'validated_by', 'validated_at'])]
class AnesthesiaRecord extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'administered_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function surgicalRequest(): BelongsTo
    {
        return $this->belongsTo(SurgicalRequest::class);
    }

    public function anesthetist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anesthetist_id');
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
