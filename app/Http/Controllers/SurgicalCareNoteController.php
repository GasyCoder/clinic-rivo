<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\RecordSurgicalCareNoteAction;
use App\Enums\SurgicalCarePhase;
use App\Http\Requests\StoreSurgicalCareNoteRequest;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Two routes (perioperative/postoperative), not a single one with a
 * request-supplied phase: they're gated by two distinct CDC permissions
 * (surgery.care.create vs surgery.postoperative_care.create) — see
 * SurgicalCareNote's own doc comment.
 */
class SurgicalCareNoteController extends Controller
{
    public function storePerioperative(StoreSurgicalCareNoteRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalCareNoteAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, SurgicalCarePhase::Perioperative, $request->validated('note'));

        return back()->with('status', 'Soin peropératoire enregistré.');
    }

    public function storePostoperative(StoreSurgicalCareNoteRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalCareNoteAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, SurgicalCarePhase::Postoperative, $request->validated('note'));

        return back()->with('status', 'Soin postopératoire enregistré.');
    }
}
