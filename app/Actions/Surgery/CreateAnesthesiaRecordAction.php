<?php

namespace App\Actions\Surgery;

use App\Models\AnesthesiaRecord;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class CreateAnesthesiaRecordAction
{
    /**
     * @param  array{anesthetist_id?: int, notes?: ?string, administered_at?: ?string}  $data
     */
    public function execute(SurgicalRequest $surgicalRequest, array $data): AnesthesiaRecord
    {
        return $surgicalRequest->anesthesiaRecord()->create([
            'anesthetist_id' => $data['anesthetist_id'] ?? Auth::id(),
            'notes' => $data['notes'] ?? null,
            'administered_at' => $data['administered_at'] ?? null,
        ]);
    }
}
