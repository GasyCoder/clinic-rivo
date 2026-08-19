<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalRequest;
use App\Models\User;

class ValidatePreoperativeAssessmentAction
{
    public function execute(SurgicalRequest $surgicalRequest, User $validator): SurgicalRequest
    {
        $surgicalRequest->validatePreoperative($validator);

        return $surgicalRequest;
    }
}
