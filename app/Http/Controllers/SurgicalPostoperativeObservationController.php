<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\RecordSurgicalPostoperativeObservationAction;
use App\Actions\Surgery\RemoveSurgicalPostoperativeObservationAction;
use App\Http\Requests\StoreSurgicalPostoperativeObservationRequest;
use App\Models\SurgicalPostoperativeObservation;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

class SurgicalPostoperativeObservationController extends Controller
{
    public function store(StoreSurgicalPostoperativeObservationRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalPostoperativeObservationAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated(), $request->user());

        return back()->with('status', 'Constantes postopératoires enregistrées.');
    }

    public function destroy(SurgicalRequest $surgicalRequest, SurgicalPostoperativeObservation $observation, RemoveSurgicalPostoperativeObservationAction $action): RedirectResponse
    {
        $action->execute($observation);

        return back()->with('status', 'Ligne de constantes postopératoires retirée.');
    }
}
