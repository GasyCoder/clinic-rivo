<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\RecordSurgicalComplicationAction;
use App\Http\Requests\StoreSurgicalComplicationRequest;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

class SurgicalComplicationController extends Controller
{
    public function store(StoreSurgicalComplicationRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalComplicationAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated('description'));

        return back()->with('status', 'Complication enregistrée.');
    }
}
