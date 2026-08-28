<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class ImmutableStaffBlockCreditMovementBuilder extends Builder
{
    public function update(array $values): int
    {
        throw new LogicException('Un mouvement de crédit Bloc ne peut pas être modifié.');
    }

    public function delete(): int
    {
        throw new LogicException('Un mouvement de crédit Bloc ne peut pas être supprimé.');
    }

    public function forceDelete(): mixed
    {
        throw new LogicException('Un mouvement de crédit Bloc ne peut pas être supprimé définitivement.');
    }
}
