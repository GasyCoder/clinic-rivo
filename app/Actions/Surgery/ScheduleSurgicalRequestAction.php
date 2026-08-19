<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalRequest;
use App\Models\User;

class ScheduleSurgicalRequestAction
{
    public function execute(SurgicalRequest $surgicalRequest, User $surgeon, string $scheduledAt): SurgicalRequest
    {
        $surgicalRequest->schedule($surgeon, $scheduledAt);

        return $surgicalRequest;
    }
}
