<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalReport;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class CreateSurgicalReportAction
{
    public function execute(SurgicalRequest $surgicalRequest, string $content): SurgicalReport
    {
        return $surgicalRequest->report()->create([
            'authored_by' => Auth::id(),
            'content' => $content,
        ]);
    }
}
