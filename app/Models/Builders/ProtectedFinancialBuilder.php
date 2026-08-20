<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Financial rows may be corrected through explicit transitions, but a
 * query-builder delete must never bypass the model-level deleting guard.
 */
class ProtectedFinancialBuilder extends Builder
{
    public function delete(): int
    {
        throw new LogicException('Protected financial records cannot be deleted.');
    }

    public function forceDelete(): mixed
    {
        throw new LogicException('Protected financial records cannot be force-deleted.');
    }
}
