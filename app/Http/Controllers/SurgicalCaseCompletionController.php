<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\CompleteSurgicalCaseAction;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ADR-170 — clore le dossier chirurgical, désormais un geste à part entière :
 * valider le compte rendu ne le fait plus au passage.
 */
class SurgicalCaseCompletionController extends Controller
{
    public function complete(
        Request $request,
        SurgicalRequest $surgicalRequest,
        CompleteSurgicalCaseAction $action,
    ): RedirectResponse {
        // Le contrôleur de base ne porte pas AuthorizesRequests : la Gate est
        // appelée nommément, comme partout ailleurs dans ce projet.
        abort_unless($request->user()->can('complete', $surgicalRequest), 403);

        $action->execute($surgicalRequest, $request->user());

        return back()->with('status', 'Dossier chirurgical clôturé.');
    }
}
