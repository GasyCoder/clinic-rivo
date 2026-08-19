<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * ADR-005 / CDC §5: the local SQL `id` never leaves the site's own
 * database; `uuid` is the distributed identifier used once an entity is
 * exchanged between sites over the API (not built yet — this only
 * prepares the identifier). Not every model needs this, only ones the CDC
 * lists as API-exchangeable (patients, users, ...).
 *
 * Migration needs, alongside id():
 *   $table->uuid('uuid')->nullable()->unique();
 *
 * Nullable at the schema level deliberately — a model adopting this trait
 * on an existing table with existing rows needs a one-off backfill
 * migration (existing rows have no uuid until then), and altering a
 * column to NOT NULL afterward is fragile across SQLite (tests) vs MySQL
 * (dev/prod). Every row created *after* adopting the trait always gets
 * one via the `creating` hook below — in practice it is never null except
 * during that narrow backfill window right after a migration runs.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Public web/API routes identify distributed entities by UUID. The
     * numeric primary key remains available only for local relationships.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
