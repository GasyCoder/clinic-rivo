<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\ResetSurgicalRequestAction;
use App\Http\Requests\ResetSurgicalRequestRequest;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

/** ADR-171 — remet à zéro un dossier du bloc saisi à tort, après archivage. */
class SurgicalRequestResetController extends Controller
{
    public function store(ResetSurgicalRequestRequest $request, SurgicalRequest $surgicalRequest, ResetSurgicalRequestAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated('reason'), $request->user());

        return back()->with('status', 'Dossier du bloc réinitialisé : il repart « À programmer ». Les saisies retirées sont archivées.');
    }
}
