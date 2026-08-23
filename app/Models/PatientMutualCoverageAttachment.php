<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_mutual_coverage_id', 'path', 'original_name', 'mime_type',
    'size', 'uploaded_by',
])]
class PatientMutualCoverageAttachment extends Model
{
    use Auditable, HasUuid;

    protected $appends = ['is_image'];

    protected $hidden = ['id', 'patient_mutual_coverage_id', 'path', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function coverage(): BelongsTo
    {
        return $this->belongsTo(PatientMutualCoverage::class, 'patient_mutual_coverage_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getIsImageAttribute(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    protected function auditModule(): ?string
    {
        return 'reception';
    }
}
