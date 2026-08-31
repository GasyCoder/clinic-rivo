<?php

namespace App\Actions\Administration;

use App\Models\EmploymentContract;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreEmploymentContractAction
{
    public function execute(EmploymentContract $contract, User $actor): EmploymentContract
    {
        Gate::forUser($actor)->authorize('restore', $contract);
        $contract->restore();

        return $contract->refresh();
    }
}
