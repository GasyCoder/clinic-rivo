<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\Episode;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class CreateSurgicalRequestAction
{
    /**
     * @param  array{catalog_item_id?: ?int, procedure_name: string, procedure_details?: ?string, notes?: ?string}  $data
     */
    public function execute(Episode $episode, array $data): SurgicalRequest
    {
        return $episode->surgicalRequests()->create([
            'requested_by' => Auth::id(),
            'created_by' => Auth::id(),
            'status' => SurgicalRequestStatus::Pending,
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'procedure_name' => $data['procedure_name'],
            'procedure_details' => $data['procedure_details'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
