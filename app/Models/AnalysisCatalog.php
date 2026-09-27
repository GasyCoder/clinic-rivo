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
    'catalog_item_id', 'parent_id', 'code', 'level', 'designation', 'description',
    'exam_category', 'result_type', 'reference_general', 'reference_male', 'reference_female',
    'reference_child_male', 'reference_child_female', 'unit', 'predefined_values',
    'display_order', 'is_active', 'is_bold', 'created_by', 'updated_by',
    'external_created_by_uuid', 'external_created_by_name',
    'external_updated_by_uuid', 'external_updated_by_name',
    'source_system', 'source_id', 'source_metadata',
])]
class AnalysisCatalog extends Model
{
    use Auditable, HasUuid, SoftDeletable;

    public const LEVELS = ['PARENT', 'CHILD', 'NORMAL'];

    public const RESULT_TYPES = ['NUMERIC', 'TEXT', 'CHOICE', 'BOOLEAN'];

    public const CONTAINER_LEVEL = 'PARENT';

    public const TERMINAL_LEVEL = 'CHILD';

    public const STANDALONE_LEVEL = 'NORMAL';

    protected function casts(): array
    {
        return [
            'predefined_values' => 'array',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_bold' => 'boolean',
            'source_id' => 'integer',
            'source_metadata' => 'array',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order')->orderBy('designation');
    }

    public function acceptsChildren(): bool
    {
        return $this->level === self::CONTAINER_LEVEL;
    }

    public function requiresParent(): bool
    {
        return $this->level === self::TERMINAL_LEVEL;
    }

    public function mayHaveParent(): bool
    {
        return in_array($this->level, [self::CONTAINER_LEVEL, self::TERMINAL_LEVEL], true);
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
        return $this->children()->withTrashed()->exists();
    }

    protected function auditModule(): ?string
    {
        return 'laboratory';
    }
}
