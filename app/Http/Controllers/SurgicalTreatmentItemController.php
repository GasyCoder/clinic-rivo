<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\RecordSurgicalTreatmentItemAction;
use App\Actions\Surgery\RemoveSurgicalTreatmentItemAction;
use App\Enums\SurgicalTreatmentCategory;
use App\Enums\SurgicalTreatmentPhase;
use App\Http\Requests\StorePostoperativeSurgicalTreatmentRequest;
use App\Http\Requests\StorePreliminarySurgicalTreatmentRequest;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTreatmentItem;
use Illuminate\Http\RedirectResponse;

class SurgicalTreatmentItemController extends Controller
{
    public function storePreliminary(StorePreliminarySurgicalTreatmentRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalTreatmentItemAction $action): RedirectResponse
    {
        $action->execute(
            $surgicalRequest,
            SurgicalTreatmentPhase::Preliminary,
            SurgicalTreatmentCategory::from($request->validated('category')),
            $request->safe()->only(['label', 'quantity', 'unit']),
            $request->user(),
        );

        return back()->with('status', 'Traitement préliminaire ajouté.');
    }

    public function destroyPreliminary(SurgicalRequest $surgicalRequest, SurgicalTreatmentItem $treatmentItem, RemoveSurgicalTreatmentItemAction $action): RedirectResponse
    {
        $action->execute($treatmentItem, SurgicalTreatmentPhase::Preliminary);

        return back()->with('status', 'Ligne de traitement préliminaire retirée.');
    }

    public function storePostoperative(StorePostoperativeSurgicalTreatmentRequest $request, SurgicalRequest $surgicalRequest, RecordSurgicalTreatmentItemAction $action): RedirectResponse
    {
        $action->execute(
            $surgicalRequest,
            SurgicalTreatmentPhase::Postoperative,
            SurgicalTreatmentCategory::from($request->validated('category')),
            $request->safe()->only(['label', 'quantity', 'unit']),
            $request->user(),
        );

        return back()->with('status', 'Traitement postopératoire ajouté.');
    }

    public function destroyPostoperative(SurgicalRequest $surgicalRequest, SurgicalTreatmentItem $treatmentItem, RemoveSurgicalTreatmentItemAction $action): RedirectResponse
    {
        $action->execute($treatmentItem, SurgicalTreatmentPhase::Postoperative);

        return back()->with('status', 'Ligne de traitement postopératoire retirée.');
    }
}
