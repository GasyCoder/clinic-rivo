<?php

namespace App\Actions\Administration;

use App\Models\HrReferenceValue;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class CreateHrReferenceValueAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): HrReferenceValue
    {
        Gate::forUser($actor)->authorize('create', HrReferenceValue::class);

        return HrReferenceValue::query()->create($data);
    }
}
