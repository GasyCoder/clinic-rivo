<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalConsumable;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class RecordSurgicalConsumableAction
{
    public function execute(SurgicalRequest $surgicalRequest, string $label, int $quantity = 1, ?string $unit = null): SurgicalConsumable
    {
        return $surgicalRequest->consumables()->create([
            'label' => $label,
            'quantity' => $quantity,
            'unit' => $unit,
            'recorded_by' => Auth::id(),
        ]);
    }
}
