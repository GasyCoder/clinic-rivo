<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\DecideAnesthesiaClearanceAction;
use App\Actions\Surgery\ResolveAnesthesiaClearanceConditionAction;
use App\Http\Requests\DecideAnesthesiaClearanceRequest;
use App\Http\Requests\ResolveAnesthesiaClearanceConditionRequest;
use App\Models\AnesthesiaClearanceCondition;
use App\Models\AnesthesiaRecord;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;

/**
 * ADR-170 — la décision d'anesthésie et ses réserves.
 *
 * Séparé d'`AnesthesiaController` parce que ce n'est pas la même chose :
 * celui-ci écrit le dossier, celui-là prononce une autorisation dont le bloc
 * dépend.
 */
class AnesthesiaClearanceController extends Controller
{
    public function decide(
        DecideAnesthesiaClearanceRequest $request,
        SurgicalRequest $surgicalRequest,
        AnesthesiaRecord $anesthesiaRecord,
        DecideAnesthesiaClearanceAction $action,
    ): RedirectResponse {
        abort_unless($anesthesiaRecord->surgical_request_id === $surgicalRequest->getKey(), 404);

        $record = $action->execute($anesthesiaRecord, $request->validated(), $request->user());

        return back()->with('status', 'Décision d’anesthésie enregistrée — '.$record->clearance_status->label().'.');
    }

    public function resolveCondition(
        ResolveAnesthesiaClearanceConditionRequest $request,
        SurgicalRequest $surgicalRequest,
        AnesthesiaRecord $anesthesiaRecord,
        AnesthesiaClearanceCondition $condition,
        ResolveAnesthesiaClearanceConditionAction $action,
    ): RedirectResponse {
        abort_unless($anesthesiaRecord->surgical_request_id === $surgicalRequest->getKey(), 404);
        abort_unless($condition->anesthesia_record_id === $anesthesiaRecord->getKey(), 404);

        $action->execute($condition, $request->validated('notes'), $request->user());

        return back()->with('status', 'Condition levée.');
    }
}
