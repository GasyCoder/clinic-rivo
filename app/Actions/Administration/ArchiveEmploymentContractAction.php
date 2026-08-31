<?php

namespace App\Actions\Administration;

use App\Models\EmploymentContract;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ArchiveEmploymentContractAction
{
    public function execute(EmploymentContract $contract, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $contract);

        $contract->delete_reason = $reason;
        $contract->delete();
    }
}
