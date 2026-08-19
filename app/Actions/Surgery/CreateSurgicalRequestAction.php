<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\Episode;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class CreateSurgicalRequestAction
{
    /**
     * @param  array{procedure_name: string, notes?: ?string}  $data
     */
    public function execute(Episode $episode, array $data): SurgicalRequest
    {
        return $episode->surgicalRequests()->create([
            'requested_by' => Auth::id(),
            'created_by' => Auth::id(),
            'status' => SurgicalRequestStatus::Pending,
            'procedure_name' => $data['procedure_name'],
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
