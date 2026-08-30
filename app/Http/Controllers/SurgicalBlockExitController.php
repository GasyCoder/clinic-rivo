<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\UpdateSurgicalBlockExitAction;
use App\Http\Requests\UpdateSurgicalBlockExitRequest;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

class SurgicalBlockExitController extends Controller
{
    public function update(UpdateSurgicalBlockExitRequest $request, SurgicalRequest $surgicalRequest, UpdateSurgicalBlockExitAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated(), $request->user());

        return back()->with('status', 'Fiche de sortie du bloc enregistrée.');
    }
}
