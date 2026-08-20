<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalComplication;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class RecordSurgicalComplicationAction
{
    public function execute(SurgicalRequest $surgicalRequest, string $description): SurgicalComplication
    {
        return $surgicalRequest->complications()->create([
            'description' => $description,
            'reported_by' => Auth::id(),
            'reported_at' => now(),
        ]);
    }
}
