<?php

namespace App\Actions\Administration;

use App\Models\HrReferenceValue;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateHrReferenceValueAction
{
    /** @param array<string, mixed> $data */
    public function execute(HrReferenceValue $reference, array $data, User $actor): HrReferenceValue
    {
        Gate::forUser($actor)->authorize('update', $reference);
        $reference->fill($data)->save();

        return $reference->refresh();
    }
}
