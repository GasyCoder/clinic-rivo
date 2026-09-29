<?php

namespace App\Actions\Administration;

use App\Models\Bank;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * ADR-221 — une banque archivée n'est plus proposée ; les fiches qui la portent la gardent.
 */
class ArchiveBankAction
{
    public function execute(Bank $bank, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $bank);
        $bank->delete_reason = $reason;
        $bank->delete();
    }
}
