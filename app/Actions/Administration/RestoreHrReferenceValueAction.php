<?php

namespace App\Actions\Administration;

use App\Models\HrReferenceValue;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class RestoreHrReferenceValueAction
{
    public function execute(HrReferenceValue $reference, User $actor): HrReferenceValue
    {
        Gate::forUser($actor)->authorize('restore', $reference);
        $reference->restore();

        return $reference->refresh();
    }
}
