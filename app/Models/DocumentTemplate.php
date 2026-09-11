<?php

namespace App\Models;

use App\Enums\DocumentDataContext;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\SoftDeletable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'lineage_id', 'document_type', 'data_context', 'name', 'description', 'content', 'content_html', 'variables_used', 'active',
    'created_by', 'updated_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_updated_by_uuid', 'external_updated_by_name',
])]
class DocumentTemplate extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    protected function casts(): array
    {
        return [
            'data_context' => DocumentDataContext::class,
            'content' => 'array',
            'variables_used' => 'array',
            'active' => 'boolean',
        ];
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /** Every version (including archived ones) sharing this canevas's lineage. */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'lineage_id', 'lineage_id')
            ->withTrashed()
            ->latest('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
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
