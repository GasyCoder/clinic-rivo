<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\CreateSurgicalInterventionAction;
use App\Actions\Surgery\UpdateSurgicalInterventionAction;
use App\Http\Requests\StoreSurgicalInterventionRequest;
use App\Http\Requests\UpdateSurgicalInterventionRequest;
use App\Models\SurgicalIntervention;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

class SurgicalInterventionController extends Controller
{
    public function store(StoreSurgicalInterventionRequest $request, SurgicalRequest $surgicalRequest, CreateSurgicalInterventionAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated());

        return back()->with('status', 'Intervention démarrée.');
    }

    public function update(UpdateSurgicalInterventionRequest $request, SurgicalRequest $surgicalRequest, SurgicalIntervention $intervention, UpdateSurgicalInterventionAction $action): RedirectResponse
    {
        abort_unless($intervention->surgical_request_id === $surgicalRequest->getKey(), 404);

        $action->execute($intervention, $request->validated());

        return back()->with('status', 'Intervention mise à jour.');
    }
}
