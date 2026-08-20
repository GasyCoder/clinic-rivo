<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\RecordSurgicalConsumableAction;
use App\Actions\Surgery\RemoveSurgicalConsumableAction;
use App\Http\Requests\StoreSurgicalConsumableRequest;
use App\Models\SurgicalConsumable;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

class SurgicalConsumableController extends Controller
{
    public function store(StoreSurgicalConsumableRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalConsumableAction $action): RedirectResponse
    {
        $action->execute(
            $surgicalRequest,
            $request->validated('label'),
            (int) $request->validated('quantity'),
            $request->validated('unit'),
        );

        return back()->with('status', 'Consommable enregistré.');
    }

    public function destroy(SurgicalRequest $surgicalRequest, SurgicalConsumable $consumable, RemoveSurgicalConsumableAction $action): RedirectResponse
    {
        $action->execute($consumable);

        return back()->with('status', 'Consommable retiré.');
    }
}
