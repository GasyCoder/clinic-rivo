<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalIntervention;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class CreateSurgicalInterventionAction
{
    /**
     * @param  array{performed_by?: int, started_at?: ?string, notes?: ?string}  $data
     */
    public function execute(SurgicalRequest $surgicalRequest, array $data): SurgicalIntervention
    {
        $surgicalRequest->startIntervention();

        return $surgicalRequest->intervention()->create([
            'performed_by' => $data['performed_by'] ?? Auth::id(),
            'started_at' => $data['started_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
