<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\CompleteLabItemAction;
use App\Actions\Laboratory\FlagCriticalLabResultAction;
use App\Actions\Laboratory\RecordLabResultAction;
use App\Actions\Laboratory\ReturnLabItemAction;
use App\Actions\Laboratory\SaveLabAntibiogramAction;
use App\Actions\Laboratory\SaveLabResultsAction;
use App\Actions\Laboratory\ValidateLabItemAction;
use App\Http\Requests\Laboratory\SaveLabAntibiogramRequest;
use App\Http\Requests\Laboratory\SaveLabResultsRequest;
use App\Http\Requests\RecordLabResultRequest;
use App\Models\LabAntibiogram;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\Patient;
use App\Services\Laboratory\LabQueue;
use App\Services\Laboratory\LabWorkbench;
use App\Support\Laboratory\LabEntryOptions;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-213 — la paillasse : la file par demande, l'espace de saisie d'une
 * demande, les gestes du technicien (enregistrer, terminer) et du biologiste
 * (valider, renvoyer), la feuille de résultats imprimée.
 *
 * Le Laboratoire n'encaisse rien (ADR-012, ADR-014) : aucun montant n'est servi.
 */
class LaboratoryController extends Controller
{
    public function index(Request $request, LabQueue $queue): Response
    {
        $view = in_array($request->query('view'), LabQueue::VIEWS, true) ? $request->query('view') : 'to_do';
        $search = str((string) $request->query('q'))->squish()->limit(80, '')->toString();

        $refine = fn ($query) => $query->when($search !== '', fn ($query) => $query->whereHas('episode', fn ($episode) => $episode
            ->where('episode_number', 'like', "%{$search}%")
            ->orWhereHas('patient', fn ($patient) => $patient
                ->where('patient_number', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%"))));

        $requests = $queue->scope($queue->base(), $view)
            ->tap($refine)
            ->with([
                'items:id,uuid,lab_request_id,catalog_item_name_snapshot,catalog_item_code_snapshot,status,resulted_at,validated_at',
                'items.results:id,lab_request_item_id,is_critical,interpretation',
                'episode:id,uuid,episode_number,patient_id,priority',
                'episode.patient:id,uuid,patient_number,first_name,last_name,birth_date,declared_age,sex',
                'requestedBy:id,name',
            ])
            ->orderByRaw('requested_at IS NULL')
            ->when(in_array($view, ['validated', 'all'], true), fn ($query) => $query->latest('requested_at'), fn ($query) => $query->oldest('requested_at'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LabRequest $labRequest) => [
                'uuid' => $labRequest->uuid,
                'state' => LabQueue::stateOf($labRequest),
                'origin' => ParaclinicalRequestPresenter::origin($labRequest),
                'requested_at' => $labRequest->requested_at,
                'requested_by' => $labRequest->requestedBy?->name,
                'emergency' => $labRequest->episode?->priority?->value === 'EMERGENCY',
                'patient' => $this->patient($labRequest->episode->patient),
                'episode_number' => $labRequest->episode->episode_number,
                'items' => $labRequest->items->map(fn (LabRequestItem $item) => [
                    'uuid' => $item->uuid,
                    'name' => $item->catalog_item_name_snapshot,
                    'status' => $item->currentStatus()->value,
                    'status_label' => $item->currentStatus()->label(),
                ])->values(),
                'critical' => $labRequest->items->flatMap->results->where('is_critical', true)->count(),
                'pathological' => $labRequest->items->flatMap->results->where('interpretation', 'PATHOLOGICAL')->count(),
            ]);

        return Inertia::render('Laboratory/Index', [
            'requests' => $requests,
            'counts' => [
                ...$queue->counts($refine),
                'validated_today' => LabRequestItem::query()->whereDate('validated_at', now()->toDateString())->count(),
            ],
            'view' => $view,
            'search' => $search,
        ]);
    }

    public function show(Request $request, LabRequest $labRequest, LabWorkbench $workbench): Response
    {
        $labRequest->load(['items', 'episode.patient', 'requestedBy:id,name']);
        $user = $request->user();
        $items = $labRequest->items->sortBy('id')->map(fn (LabRequestItem $item) => $workbench->present($item))->values();
        $needsCulture = $items->contains(fn (array $item) => collect($item['nodes'])->contains('entry_mode', 'CULTURE'));

        return Inertia::render('Laboratory/Show', [
            'labRequest' => [
                'uuid' => $labRequest->uuid,
                'state' => LabQueue::stateOf($labRequest),
                'cancelled' => $labRequest->cancelled_at !== null,
                'cancel_reason' => $labRequest->cancel_reason,
                'origin' => ParaclinicalRequestPresenter::origin($labRequest),
                'notes' => $labRequest->notes,
                'requested_at' => $labRequest->requested_at,
                'requested_by' => $labRequest->requestedBy?->name,
                'episode_number' => $labRequest->episode->episode_number,
                'episode_uuid' => $labRequest->episode->uuid,
                'emergency' => $labRequest->episode->priority?->value === 'EMERGENCY',
                'patient' => $this->patient($labRequest->episode->patient),
            ],
            'items' => $items,
            'microbiology' => $needsCulture ? $workbench->microbiology() : [],
            'options' => LabEntryOptions::forScreen(),
            'can' => [
                'enter' => $user->can('laboratory_results.create'),
                'validate' => $user->can('laboratory_results.validate'),
                'flag_critical' => $user->can('laboratory_results.flag_critical'),
                'microbiology' => $user->can('lab_microbiology.view'),
            ],
        ]);
    }

    public function print(LabRequest $labRequest, LabWorkbench $workbench): Response
    {
        $labRequest->load(['items', 'episode.patient', 'requestedBy:id,name']);

        return Inertia::render('Laboratory/ResultsPrint', [
            'labRequest' => [
                'uuid' => $labRequest->uuid,
                'requested_at' => $labRequest->requested_at,
                'requested_by' => $labRequest->requestedBy?->name,
                'origin' => ParaclinicalRequestPresenter::origin($labRequest),
                'episode_number' => $labRequest->episode->episode_number,
                'patient' => $this->patient($labRequest->episode->patient),
            ],
            // Seul ce qui est rendu s'imprime ; « non validé » reste écrit sur la feuille.
            'items' => $labRequest->items->sortBy('id')
                ->filter(fn (LabRequestItem $item) => $item->resulted_at !== null)
                ->map(fn (LabRequestItem $item) => $workbench->present($item))
                ->values(),
            'options' => LabEntryOptions::forScreen(),
        ]);
    }

    public function saveResults(SaveLabResultsRequest $request, LabRequestItem $labRequestItem, SaveLabResultsAction $action): RedirectResponse
    {
        $action->execute($labRequestItem, $request->validated(), $request->user());

        return back();
    }

    public function saveAntibiogram(
        SaveLabAntibiogramRequest $request,
        LabRequestItem $labRequestItem,
        LabAntibiogram $labAntibiogram,
        SaveLabAntibiogramAction $action,
    ): RedirectResponse {
        $action->execute($labRequestItem, $labAntibiogram, $request->validated('lines') ?? [], $request->validated('notes'), $request->user());

        return back();
    }

    public function complete(Request $request, LabRequestItem $labRequestItem, CompleteLabItemAction $action): RedirectResponse
    {
        $item = $action->execute($labRequestItem, $request->user());

        return back()->with('status', "« {$item->catalog_item_name_snapshot} » terminée : elle attend la validation du biologiste.");
    }

    public function returnItem(Request $request, LabRequestItem $labRequestItem, ReturnLabItemAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [
            'reason.required' => 'Indiquez pourquoi l’analyse est à refaire.',
            'reason.min' => 'Indiquez pourquoi l’analyse est à refaire.',
        ]);
        $item = $action->execute($labRequestItem, $validated['reason'], $request->user());

        return back()->with('status', "« {$item->catalog_item_name_snapshot} » renvoyée à refaire.");
    }

    public function validateItem(Request $request, LabRequestItem $labRequestItem, ValidateLabItemAction $action): RedirectResponse
    {
        $item = $action->execute($labRequestItem, $request->user());

        return back()->with('status', "« {$item->catalog_item_name_snapshot} » validée.");
    }

    public function validateRequest(Request $request, LabRequest $labRequest, ValidateLabItemAction $action): RedirectResponse
    {
        $count = $action->executeForRequest($labRequest, $request->user());

        return back()->with('status', $count === 1 ? 'Une analyse validée.' : "{$count} analyses validées.");
    }

    public function flagCritical(Request $request, LabResult $labResult, FlagCriticalLabResultAction $action): RedirectResponse
    {
        $validated = $request->validate(['critical' => ['required', 'boolean']]);
        $action->execute($labResult, (bool) $validated['critical'], $request->user());

        return back()->with('status', $validated['critical'] ? 'Résultat signalé critique.' : 'Signalement critique retiré.');
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

        return back()->with('status', 'Résultat enregistré : il attend la validation du biologiste.');
    }

    /** @return array<string, mixed> Ce qu'il faut pour reconnaître le patient, rien de clinique. */
    private function patient(Patient $patient): array
    {
        return [
            'uuid' => $patient->uuid,
            'patient_number' => $patient->patient_number,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'sex' => $patient->sex?->value,
            'age' => $patient->birth_date?->age ?? $patient->declared_age,
            'birth_date' => $patient->birth_date?->toDateString(),
        ];
    }
}
