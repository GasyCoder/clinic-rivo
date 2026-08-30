<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\RecordLabResultAction;
use App\Http\Requests\RecordLabResultRequest;
use App\Models\LabRequestItem;
use App\Services\Laboratory\AnalysisReferenceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LaboratoryController extends Controller
{
    public function index(Request $request, AnalysisReferenceResolver $references): Response
    {
        $items = LabRequestItem::query()
            ->with([
                'catalogItem.analysisDefinitions' => fn ($query) => $query->where('is_active', true),
                'labRequest.episode.patient:id,uuid,patient_number,first_name,last_name,birth_date,declared_age,sex',
                'labRequest.requestedBy:id,name',
                'resultedBy:id,name',
            ])
            ->orderByRaw('resulted_at IS NOT NULL')
            ->latest('created_at')
            ->paginate(20)
            ->through(fn (LabRequestItem $item) => [
                'uuid' => $item->uuid,
                'name' => $item->catalog_item_name_snapshot,
                'code' => $item->catalog_item_code_snapshot,
                'result_value' => $item->result_value,
                'result_notes' => $item->result_notes,
                'resulted_at' => $item->resulted_at,
                'resulted_by' => $item->resultedBy?->name,
                'reference_definitions' => $item->reference_snapshot ?? $references->snapshot(
                    $item->catalogItem,
                    $item->labRequest->episode->patient,
                    $item->labRequest->episode->started_at ?? now(),
                ),
                'requested_at' => $item->labRequest->requested_at,
                'requested_by' => $item->labRequest->requestedBy?->name,
                'patient' => [
                    'uuid' => $item->labRequest->episode->patient->uuid,
                    'patient_number' => $item->labRequest->episode->patient->patient_number,
                    'first_name' => $item->labRequest->episode->patient->first_name,
                    'last_name' => $item->labRequest->episode->patient->last_name,
                ],
                'episode_number' => $item->labRequest->episode->episode_number,
            ]);

        return Inertia::render('Laboratory/Index', ['items' => $items]);
    }

    public function recordResult(
        RecordLabResultRequest $request,
        LabRequestItem $labRequestItem,
        RecordLabResultAction $action,
    ): RedirectResponse {
        $action->execute(
            $labRequestItem,
            $request->validated('result_value'),
            $request->input('result_notes'),
            $request->user(),
        );

        return back()->with('status', 'Résultat enregistré.');
    }
}
