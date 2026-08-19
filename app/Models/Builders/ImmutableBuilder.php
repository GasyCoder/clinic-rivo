<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Blocks bulk update/delete at the query-builder level, not just the
 * per-model event level. `AuditLog::query()->delete()` (or ->update())
 * bypasses Eloquent's `deleting`/`updating` model events entirely — those
 * only fire for individual-instance `$model->delete()`/`update()` — so a
 * bulk call would otherwise silently wipe or rewrite audit history despite
 * the model-level guards. This does not stop raw `DB::table(...)` access;
 * that requires a database-level privilege restriction (REVOKE DELETE,
 * UPDATE ON audit_logs), an ops/deployment concern outside what a portable
 * migration can enforce across this project's MySQL/SQLite environments.
 */
class ImmutableBuilder extends Builder
{
    public function update(array $values): int
    {
        throw new LogicException('Audit log entries are immutable and cannot be updated.');
    }

    public function delete(): int
    {
        throw new LogicException('Audit log entries cannot be deleted.');
    }
}
