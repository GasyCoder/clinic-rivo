<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-214 — un prélèvement d'une demande d'analyses : un tube (ou un flacon, un
 * écouvillon), son code-barres et qui l'a prélevé. Type et tube sont figés au
 * prélèvement. Un prélèvement non conforme (hémolysé, insuffisant…) n'est jamais
 * effacé : il garde son motif, et on en enregistre un autre.
 */
#[Fillable([
    'lab_request_id', 'sample_type_id', 'tube_type_id', 'sample_type_name_snapshot',
    'tube_code_snapshot', 'tube_name_snapshot', 'tube_color_snapshot', 'tube_color_hex_snapshot',
    'sequence', 'barcode', 'notes', 'collected_at', 'collected_by',
    'rejected_at', 'rejected_by', 'rejection_reason',
])]
class LabSample extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'collected_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function labRequest(): BelongsTo
    {
        return $this->belongsTo(LabRequest::class);
    }

    public function sampleType(): BelongsTo
    {
        return $this->belongsTo(LabSampleType::class, 'sample_type_id')->withTrashed();
    }

    public function tubeType(): BelongsTo
    {
        return $this->belongsTo(LabTubeType::class, 'tube_type_id')->withTrashed();
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
