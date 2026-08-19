<?php

namespace App\Models\Concerns;

use App\Services\Audit\Auditor;

/**
 * CDC §22 lists create/update among the actions "obligatoirement auditées".
 * SoftDeletable already covers delete/restore/force_delete on its own —
 * this trait covers the other two, so a fully CDC-compliant model uses
 * both:
 *
 *   use Auditable, HasUuid, SoftDeletable;
 */
trait Auditable
{
    use HasAuditModule;

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            app(Auditor::class)->record(
                'create',
                entity: $model,
                newValues: $model->getAttributes(),
                module: $model->auditModule(),
            );
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();

            // SoftDeletable::restoring() already records its own 'restore'
            // entry; its save() only ever touches deleted_at/deleted_by/
            // delete_reason/updated_at, so skip exactly that case here or
            // the same event would be audited twice, once as 'restore' and
            // once as 'update'. (The delete path itself never reaches this
            // hook at all — it writes via the query builder, which doesn't
            // fire model events.)
            if (array_diff(array_keys($changes), ['deleted_at', 'deleted_by', 'delete_reason', 'updated_at']) === []) {
                return;
            }

            if ($model->auditableSkipsChange($changes)) {
                return;
            }

            $old = collect($changes)->keys()
                ->mapWithKeys(fn ($key) => [$key => $model->getOriginal($key)])
                ->all();

            app(Auditor::class)->record(
                'update',
                entity: $model,
                newValues: $changes,
                oldValues: $old,
                module: $model->auditModule(),
            );
        });
    }
}
