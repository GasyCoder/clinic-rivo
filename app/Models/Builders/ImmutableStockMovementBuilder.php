<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * A validated stock movement is critical history (ADR-010/036). Model
 * events alone do not protect bulk update/delete calls, so guard both paths.
 */
class ImmutableStockMovementBuilder extends Builder
{
    public function update(array $values): int
    {
        throw new LogicException('Un mouvement de stock validé ne peut pas être modifié.');
    }

    public function delete(): int
    {
        throw new LogicException('Un mouvement de stock validé ne peut pas être supprimé.');
    }
}
