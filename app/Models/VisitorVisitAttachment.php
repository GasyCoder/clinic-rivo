<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'visitor_visit_id', 'path', 'original_name', 'mime_type', 'size',
])]
class VisitorVisitAttachment extends Model
{
    use Auditable, HasUuid;

    protected $appends = ['url', 'is_image'];

    protected $hidden = ['id', 'visitor_visit_id', 'path'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function visitorVisit(): BelongsTo
    {
        return $this->belongsTo(VisitorVisit::class);
    }

    public function getUrlAttribute(): ?string
    {
        $visitorUuid = $this->visitorVisit?->uuid;

        if (! $visitorUuid || ! $this->uuid) {
            return null;
        }

        return "/reception/visitors/{$visitorUuid}/attachments/{$this->uuid}";
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
