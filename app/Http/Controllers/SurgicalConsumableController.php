<?php

namespace App\Http\Controllers;

use App\Actions\Care\CancelCareConsumableRequestAction;
use App\Actions\Care\RequestCareConsumablesAction;
use App\Actions\Surgery\RecordSurgicalConsumableAction;
use App\Actions\Surgery\RemoveSurgicalConsumableAction;
use App\Http\Requests\CancelSurgicalConsumableRequestRequest;
use App\Http\Requests\RequestSurgicalConsumablesRequest;
use App\Http\Requests\StoreSurgicalConsumableRequest;
use App\Models\CareConsumableRequest;
use App\Models\SurgicalConsumable;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

/**
 * ADR-169 — deux façons de noter le matériel du bloc :
 *
 * ```text
 * stock      un produit de la Pharmacie : la demande part dans la file de la
 *            Pharmacie qui le sort du stock, la ligne rejoint le compte du patient
 * hors stock une ligne libre (produit absent du stock) : tracée, ni stock ni facture
 * ```
 */
class SurgicalConsumableController extends Controller
{
    public function requestFromStock(
        RequestSurgicalConsumablesRequest $request,
        SurgicalRequest $surgicalRequest,
        RequestCareConsumablesAction $action,
    ): RedirectResponse {
        $consumableRequest = $action->executeForSurgery($surgicalRequest, $request->validated(), $request->user());

        return back()->with('status', "Matériel transmis à la Pharmacie ({$consumableRequest->request_number}).");
    }

    public function cancelRequest(
        CancelSurgicalConsumableRequestRequest $request,
        SurgicalRequest $surgicalRequest,
        CareConsumableRequest $careConsumableRequest,
        CancelCareConsumableRequestAction $action,
    ): RedirectResponse {
        // Une demande d'un autre dossier n'est pas atteignable d'ici.
        abort_unless($careConsumableRequest->surgical_request_id === $surgicalRequest->getKey(), 404);

        $action->execute($careConsumableRequest, $request->validated('reason'), $request->user());

        return back()->with('status', 'Demande de matériel annulée. La Pharmacie ne la verra plus dans sa file.');
    }

    public function store(StoreSurgicalConsumableRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalConsumableAction $action): RedirectResponse
    {
        $action->execute(
            $surgicalRequest,
            $request->validated('label'),
            (int) $request->validated('quantity'),
            $request->validated('unit'),
        );

        return back()->with('status', 'Ligne hors stock enregistrée : ni sortie de stock ni facture.');
    }

    public function destroy(SurgicalRequest $surgicalRequest, SurgicalConsumable $consumable, RemoveSurgicalConsumableAction $action): RedirectResponse
    {
        $action->execute($consumable);

        return back()->with('status', 'Ligne hors stock retirée.');
    }
}
