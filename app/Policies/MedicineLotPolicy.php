<?php

namespace App\Policies;

use App\Models\MedicineLot;
use App\Models\User;

class MedicineLotPolicy
{
    /** A new lot requires both the stock-entry and lot-creation abilities. */
    public function create(User $user): bool
    {
        return $user->can('stock.entry') && $user->can('stock.lots.create');
    }

    /** Adding quantity to an existing active lot is a stock entry. */
    public function receive(User $user, MedicineLot $lot): bool
    {
        return $lot->active && $user->can('stock.entry');
    }

    public function adjust(User $user, MedicineLot $lot): bool
    {
        return $lot->active && $user->can('stock.adjust');
    }
}
