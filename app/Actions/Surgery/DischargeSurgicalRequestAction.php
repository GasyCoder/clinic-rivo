<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalRequest;
use App\Models\User;

class DischargeSurgicalRequestAction
{
    public function execute(SurgicalRequest $surgicalRequest, User $actor, ?string $notes = null): SurgicalRequest
    {
        $surgicalRequest->discharge($actor, $notes);

        return $surgicalRequest;
    }
}
