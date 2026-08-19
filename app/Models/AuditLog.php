<?php

namespace App\Models;

use App\Models\Builders\ImmutableBuilder;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only. Nothing in this codebase may update or delete an audit
 * entry — see the `updating`/`deleting` guards below — matching CDC §22
 * and CLAUDE.md's audit rule: sensitive operations must be traceable,
 * including SUPER_ADMIN's own actions. Both the per-instance events below
 * AND the query builder (newEloquentBuilder()) are guarded — see
 * ImmutableBuilder for why the builder-level guard is also necessary.
 */
#[Fillable([
    'user_id', 'action', 'module', 'entity_type', 'entity_id', 'entity_uuid',
    'old_values', 'new_values', 'reason', 'ip_address', 'user_agent', 'request_uuid',
])]
class AuditLog extends Model
{
    use HasUuid;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('Audit log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('Audit log entries cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function newEloquentBuilder($query): ImmutableBuilder
    {
        return new ImmutableBuilder($query);
    }
}
