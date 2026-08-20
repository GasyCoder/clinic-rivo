<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalCarePhase;
use App\Models\SurgicalCareNote;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;

class RecordSurgicalCareNoteAction
{
    public function execute(SurgicalRequest $surgicalRequest, SurgicalCarePhase $phase, string $note): SurgicalCareNote
    {
        return $surgicalRequest->careNotes()->create([
            'phase' => $phase,
            'note' => $note,
            'recorded_by' => Auth::id(),
            'recorded_at' => now(),
        ]);
    }
}
