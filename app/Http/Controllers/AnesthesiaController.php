<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\CreateAnesthesiaRecordAction;
use App\Actions\Surgery\UpdateAnesthesiaRecordAction;
use App\Actions\Surgery\ValidateAnesthesiaRecordAction;
use App\Actions\Surgery\ValidatePreanestheticAssessmentAction;
use App\Http\Requests\StoreAnesthesiaRecordRequest;
use App\Http\Requests\UpdateAnesthesiaRecordRequest;
use App\Models\AnesthesiaRecord;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnesthesiaController extends Controller
{
    public function store(StoreAnesthesiaRecordRequest $request, SurgicalRequest $surgicalRequest, CreateAnesthesiaRecordAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated());

        return back()->with('status', "Dossier d'anesthésie créé.");
    }

    public function update(UpdateAnesthesiaRecordRequest $request, SurgicalRequest $surgicalRequest, AnesthesiaRecord $anesthesiaRecord, UpdateAnesthesiaRecordAction $action): RedirectResponse
    {
        abort_unless($anesthesiaRecord->surgical_request_id === $surgicalRequest->getKey(), 404);

        $action->execute($anesthesiaRecord, $request->validated());

        return back()->with('status', "Dossier d'anesthésie mis à jour.");
    }

    public function validateRecord(Request $request, SurgicalRequest $surgicalRequest, AnesthesiaRecord $anesthesiaRecord, ValidateAnesthesiaRecordAction $action): RedirectResponse
    {
        abort_unless($anesthesiaRecord->surgical_request_id === $surgicalRequest->getKey(), 404);

        $action->execute($anesthesiaRecord);

        return back()->with('status', "Dossier d'anesthésie validé.");
    }

    public function validateAssessment(Request $request, SurgicalRequest $surgicalRequest, AnesthesiaRecord $anesthesiaRecord, ValidatePreanestheticAssessmentAction $action): RedirectResponse
    {
        abort_unless($anesthesiaRecord->surgical_request_id === $surgicalRequest->getKey(), 404);

        $action->execute($anesthesiaRecord);

        return back()->with('status', 'Évaluation pré-anesthésique validée.');
    }
}
