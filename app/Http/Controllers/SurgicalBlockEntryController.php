<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\UpdateSurgicalBlockEntryAction;
use App\Http\Requests\UpdateSurgicalBlockEntryRequest;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

class SurgicalBlockEntryController extends Controller
{
    public function update(UpdateSurgicalBlockEntryRequest $request, SurgicalRequest $surgicalRequest, UpdateSurgicalBlockEntryAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated(), $request->user());

        return back()->with('status', 'Fiche d’entrée au bloc enregistrée.');
    }
}
