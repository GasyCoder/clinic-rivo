<?php

namespace App\Actions\Administration;

use App\Models\HrReferenceValue;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ArchiveHrReferenceValueAction
{
    public function execute(HrReferenceValue $reference, string $reason, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $reference);
        $reference->delete_reason = $reason;
        $reference->delete();
    }
}
