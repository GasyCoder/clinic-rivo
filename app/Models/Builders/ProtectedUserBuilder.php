<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Users are historical actors referenced by audit and operational records.
 * Their access is revoked through the explicit deactivation workflow; their
 * database row must never be removed through a bulk query.
 */
class ProtectedUserBuilder extends Builder
{
    public function delete(): int
    {
        throw new LogicException('User accounts cannot be deleted. Deactivate the account instead.');
    }

    public function forceDelete(): mixed
    {
        throw new LogicException('User accounts cannot be force-deleted.');
    }
}
