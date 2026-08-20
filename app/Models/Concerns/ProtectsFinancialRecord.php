<?php

namespace App\Models\Concerns;

use App\Models\Builders\ProtectedFinancialBuilder;
use LogicException;

/**
 * Financial history is corrected through explicit cancel/reverse flows,
 * never by deleting the original record (ADR-010 / CDC §34.2).
 */
trait ProtectsFinancialRecord
{
    public static function bootProtectsFinancialRecord(): void
    {
        static::deleting(function ($model) {
            throw new LogicException(sprintf('%s is a protected financial record and cannot be deleted.', $model::class));
        });
    }

    public function newEloquentBuilder($query): ProtectedFinancialBuilder
    {
        return new ProtectedFinancialBuilder($query);
    }
}
