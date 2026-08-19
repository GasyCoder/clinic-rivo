<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalRequest;

class UpdateSurgicalPreparationAction
{
    public function execute(SurgicalRequest $surgicalRequest, ?string $operatingRoom, ?string $notes): SurgicalRequest
    {
        $surgicalRequest->operating_room = $operatingRoom;
        $surgicalRequest->preparation_notes = $notes;
        $surgicalRequest->save();

        return $surgicalRequest;
    }
}
