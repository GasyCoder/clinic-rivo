<?php

namespace App\Models\Builders;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Users are historical actors referenced by audit and operational records.
 * Their access is revoked through the explicit deactivation workflow; their
 * database row must never be removed through a bulk query. The one
 * sanctioned exception (ForceDeleteUserAction, a single already-loaded,
 * zero-footprint account) deletes through the model instance, never a bulk
 * query — User::allowPhysicalDeletion() does not lift this guard.
 */
class ProtectedUserBuilder extends Builder
{
    public function delete(): int
    {
        if (! User::physicalDeletionAllowed()) {
            throw new LogicException('User accounts cannot be deleted. Deactivate the account instead.');
        }

        return parent::delete();
    }

    public function forceDelete(): mixed
    {
        throw new LogicException('User accounts cannot be force-deleted.');
    }
}
