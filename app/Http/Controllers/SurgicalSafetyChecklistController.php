<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\SaveSurgicalChecklistAction;
use App\Http\Requests\SaveSurgicalChecklistRequest;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

class SurgicalSafetyChecklistController extends Controller
{
    public function save(
        SaveSurgicalChecklistRequest $request,
        SurgicalRequest $surgicalRequest,
        SaveSurgicalChecklistAction $action,
    ): RedirectResponse {
        $phase = $request->phase();

        $action->execute($surgicalRequest, $phase, $request->validated(), $request->user());

        return back()->with('status', $phase->label().' mis à jour.');
    }
}
