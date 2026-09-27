<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestOrigin;
use App\Enums\SurgicalRequestStatus;
use App\Models\Episode;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class CreateSurgicalRequestAction
{
    /**
     * ADR-159 — `origin` dit d'où vient la demande. Le bloc n'en crée aucune :
     * les trois appelants sont la Réception, la consultation et la Maternité.
     *
     * @param  array{catalog_item_id?: ?int, procedure_name: string, procedure_details?: ?string, notes?: ?string}  $data
     */
    public function execute(Episode $episode, array $data, ?SurgicalRequestOrigin $origin = null): SurgicalRequest
    {
        return $episode->surgicalRequests()->create([
            'requested_by' => Auth::id(),
            'created_by' => Auth::id(),
            'status' => SurgicalRequestStatus::Pending,
            'origin' => $origin,
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'procedure_name' => $data['procedure_name'],
            'procedure_details' => $data['procedure_details'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }
}
