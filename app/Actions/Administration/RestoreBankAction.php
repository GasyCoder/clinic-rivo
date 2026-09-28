<?php

namespace App\Actions\Administration;

use App\Models\Bank;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreBankAction
{
    public function execute(Bank $bank, User $actor): Bank
    {
        Gate::forUser($actor)->authorize('restore', $bank);
        $bank->restore();

        return $bank->refresh();
    }
}
