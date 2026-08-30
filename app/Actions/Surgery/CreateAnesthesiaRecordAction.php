<?php

namespace App\Actions\Surgery;

use App\Models\AnesthesiaRecord;
use App\Models\SurgicalRequest;
use App\Services\Surgery\AnesthesiaItemNormalizer;
use Illuminate\Support\Facades\Auth;

class CreateAnesthesiaRecordAction
{
    public function __construct(private readonly AnesthesiaItemNormalizer $itemNormalizer) {}

    /**
     * @param  array{anesthetist_id?: int, consultation_data?: ?array, paraclinical_data?: ?array, anesthetic_items?: ?array, notes?: ?string, administered_at?: ?string}  $data
     */
    public function execute(SurgicalRequest $surgicalRequest, array $data): AnesthesiaRecord
    {
        return $surgicalRequest->anesthesiaRecord()->create([
            'anesthetist_id' => $data['anesthetist_id'] ?? Auth::id(),
            'consultation_data' => $data['consultation_data'] ?? null,
            'paraclinical_data' => $data['paraclinical_data'] ?? null,
            'anesthetic_items' => $this->itemNormalizer->normalize($data['anesthetic_items'] ?? null),
            'notes' => $data['notes'] ?? null,
            'administered_at' => $data['administered_at'] ?? null,
        ]);
    }
}
