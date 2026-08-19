<?php

namespace App\Models\Concerns;

use App\Exceptions\ForceDeleteForbiddenException;
use App\Services\Audit\Auditor;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-009 / CDC §11: `resource.delete` is Soft Delete by default. Adopting
 * this trait gives a model CDC-compliant deletion for free: `deleted_at`
 * (via Laravel's own SoftDeletes), plus `deleted_by` + `delete_reason`,
 * plus automatic audit entries for delete/restore/force_delete — nothing
 * to wire per model beyond the migration columns below.
 *
 * Migration must add, alongside this trait:
 *   $table->softDeletesWithReason(); // macro registered in AppServiceProvider
 * which is exactly:
 *   $table->softDeletes();
 *   $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
 *   $table->text('delete_reason')->nullable();
 *
 * Usage:
 *   $patient->delete_reason = 'Doublon confirmé';
 *   $patient->delete();
 *
 * `force_delete` is refused whenever `isForceDeleteProtected()` returns
 * true — override it per model for CDC §11's critical-data list (settled
 * payments, validated invoices/prescriptions/lab results, cash closings,
 * completed transfers, ...). Models that are never critical don't need to
 * override it; the default is unprotected.
 */
trait SoftDeletable
{
    use SoftDeletes;

    public static function bootSoftDeletable(): void
    {
        static::deleting(function ($model) {
            if ($model->isForceDeleting()) {
                if ($model->isForceDeleteProtected()) {
                    throw new ForceDeleteForbiddenException($model);
                }

                app(Auditor::class)->record('force_delete', entity: $model, module: $model->auditModule());

                return;
            }

            app(Auditor::class)->record(
                'delete',
                entity: $model,
                reason: $model->delete_reason,
                module: $model->auditModule(),
            );
        });

        static::restoring(function ($model) {
            $model->delete_reason = null;

            app(Auditor::class)->record('restore', entity: $model, module: $model->auditModule());
        });
    }

    /**
     * Overrides SoftDeletes::runSoftDelete(): Laravel's own implementation
     * only ever writes `deleted_at` (and `updated_at`) to the database,
     * ignoring any other dirty attribute on the model — so `deleted_by`
     * and `delete_reason` need explicit inclusion here, or they would
     * silently never persist despite being set on the in-memory model.
     */
    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];
        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;
            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $this->deleted_by = $this->deletionActorId();
        $columns['deleted_by'] = $this->deleted_by;
        $columns['delete_reason'] = $this->delete_reason;

        $query->update($columns);

        $this->exists = false;
    }

    public function isForceDeleteProtected(): bool
    {
        return false;
    }

    protected function deletionActorId(): ?int
    {
        return Auth::id();
    }

    /**
     * CDC module name for the audit entry (e.g. "pharmacy", "reception").
     * Override per model — null is fine until a real module claims it.
     */
    protected function auditModule(): ?string
    {
        return null;
    }
}
