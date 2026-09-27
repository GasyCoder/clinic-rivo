<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\ServeCareConsumablesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\ServeCareConsumablesRequest;
use App\Models\CareConsumableRequest;
use App\Services\Pharmacy\PharmacyWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CareConsumableController extends Controller
{
    public function index(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/CareConsumables/Index', $workspace->careConsumables($request->user()));
    }

    /**
     * ADR-072 — records the stock exit of consumables already used at Soins.
     * Deliberately not routed through DispenseMedicinesAction: that one
     * refuses to move a lot before its invoice is settled (ADR-049), which
     * cannot apply to an item already on a patient's wound.
     */
    public function serve(
        ServeCareConsumablesRequest $request,
        CareConsumableRequest $careConsumableRequest,
        ServeCareConsumablesAction $action,
    ): RedirectResponse {
        $served = $action->execute($careConsumableRequest, $request->validated(), $request->user());

        return back()->with(
            'status',
            "Sortie de stock enregistrée pour la demande {$served->request_number} ({$served->status->label()}).",
        );
    }
}
