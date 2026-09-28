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
use App\Models\LabSample;
use App\Models\LabSampleType;
use App\Models\LabTubeType;
use App\Services\Laboratory\LabPaymentClearance;
use App\Services\Laboratory\LabQueue;
use App\Services\Laboratory\LabRequestPresenter;
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
    public function index(Request $request, LabQueue $queue, LabPaymentClearance $clearance): Response|RedirectResponse
    {
        $search = str((string) $request->query('q'))->squish()->limit(80, '')->toString();

        // ADR-214 — un code-barres scanné (tube ou numéro de laboratoire) ouvre sa demande.
        if ($search !== '' && ($scanned = $this->scanned($search)) !== null) {
            return redirect("/laboratory/requests/{$scanned}");
        }

        $sentOut = $request->boolean('externe');
        $refine = fn ($query) => $query
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('lab_number', 'like', "%{$search}%")
                ->orWhereHas('episode', fn ($episode) => $episode
                    ->where('episode_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($patient) => $patient
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")))))
            ->when($sentOut, fn ($query) => $queue->sentOut($query));

        $counts = $queue->counts($refine);
        $requested = $request->query('view');
        $view = in_array($requested, LabQueue::VIEWS, true)
            ? $requested
            // Sans vue choisie : la réception d'abord, s'il y a des patients à recevoir.
            : (($counts['to_receive'] ?? 0) > 0 ? 'to_receive' : 'to_do');

        $requests = $queue->scope($queue->base(), $view)
            ->tap($refine)
            ->with([
                'items:id,uuid,lab_request_id,catalog_item_name_snapshot,catalog_item_code_snapshot,status,resulted_at,validated_at,sent_out_at,external_lab_name,billable_item_id',
                'items.results:id,lab_request_item_id,is_critical,interpretation',
                'episode:id,uuid,episode_number,patient_id,priority',
                'episode.patient:id,uuid,patient_number,first_name,last_name,birth_date,declared_age,sex',
                'requestedBy:id,name',
                ...($view === 'to_receive' ? LabPaymentClearance::RELATIONS : []),
            ])
            ->withCount(['samples' => fn ($samples) => $samples->whereNull('rejected_at')])
            ->orderByRaw('requested_at IS NULL')
            ->when(in_array($view, ['validated', 'all'], true), fn ($query) => $query->latest('requested_at'), fn ($query) => $query->oldest('requested_at'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LabRequest $labRequest) => [
                'uuid' => $labRequest->uuid,
                'lab_number' => $labRequest->lab_number,
                'state' => LabQueue::stateOf($labRequest),
                'origin' => ParaclinicalRequestPresenter::origin($labRequest),
                'requested_at' => $labRequest->requested_at,
                'requested_by' => $labRequest->requestedBy?->name,
                'emergency' => $labRequest->episode?->priority?->value === 'EMERGENCY',
                'patient' => LabRequestPresenter::patient($labRequest->episode->patient),
                'episode_number' => $labRequest->episode->episode_number,
                'samples_count' => $labRequest->samples_count,
                'payment' => $view === 'to_receive' ? collect($clearance->for($labRequest))->only(['cleared', 'exemption', 'exemption_label', 'due_count', 'unbilled_count'])->all() : null,
                'items' => $labRequest->items->map(fn (LabRequestItem $item) => [
                    'uuid' => $item->uuid,
                    'name' => $item->catalog_item_name_snapshot,
                    'status' => $item->currentStatus()->value,
                    'status_label' => $item->currentStatus()->label(),
                    'external' => $item->sent_out_at ? $item->external_lab_name : null,
                ])->values(),
                'critical' => $labRequest->items->flatMap->results->where('is_critical', true)->count(),
                'pathological' => $labRequest->items->flatMap->results->where('interpretation', 'PATHOLOGICAL')->count(),
            ]);

        return Inertia::render('Laboratory/Index', [
            'requests' => $requests,
            'counts' => [
                ...$counts,
                'validated_today' => LabRequestItem::query()->whereDate('validated_at', now()->toDateString())->count(),
                'sent_out' => $queue->sentOut($queue->base())->count(),
            ],
            'view' => $view,
            'search' => $search,
            'sentOut' => $sentOut,
        ]);
    }

    public function show(
        Request $request,
        LabRequest $labRequest,
        LabWorkbench $workbench,
        LabRequestPresenter $presenter,
        LabPaymentClearance $clearance,
    ): Response {
        $labRequest->load(['items', 'episode.patient', 'requestedBy:id,name']);
        $user = $request->user();
        $items = $labRequest->items->sortBy('id')->map(fn (LabRequestItem $item) => $workbench->present($item))->values();
        $needsCulture = $items->contains(fn (array $item) => collect($item['nodes'])->contains('entry_mode', 'CULTURE'));
        $received = $labRequest->received_at !== null;
        $canSample = $user->can('laboratory_samples.create');

        return Inertia::render('Laboratory/Show', [
            'labRequest' => $presenter->header($labRequest),
            'items' => $items,
            'samples' => $presenter->samples($labRequest),
            // ADR-214 — le contrôle du règlement ne sert qu'avant la réception.
            'payment' => $received ? null : $clearance->for($labRequest),
            'sampleOptions' => ($canSample || $user->can('laboratory_orders.receive')) && ! $labRequest->cancelled_at ? $this->sampleOptions() : null,
            'externalLabs' => $user->can('laboratory_orders.send_out') ? $this->externalLabs() : [],
            'microbiology' => $needsCulture ? $workbench->microbiology() : [],
            'options' => LabEntryOptions::forScreen(),
            'can' => [
                'enter' => $user->can('laboratory_results.create'),
                'validate' => $user->can('laboratory_results.validate'),
                'flag_critical' => $user->can('laboratory_results.flag_critical'),
                'microbiology' => $user->can('lab_microbiology.view'),
                'receive' => $user->can('laboratory_orders.receive'),
                'sample' => $canSample,
                'reject_sample' => $user->can('laboratory_samples.update'),
                'send_out' => $user->can('laboratory_orders.send_out'),
                'history' => $user->can('laboratory_results.view'),
            ],
        ]);
    }

    public function print(LabRequest $labRequest, LabWorkbench $workbench, LabRequestPresenter $presenter): Response
    {
        $labRequest->load(['items', 'episode.patient', 'requestedBy:id,name']);

        return Inertia::render('Laboratory/ResultsPrint', [
            'labRequest' => $presenter->header($labRequest),
            'samples' => collect($presenter->samples($labRequest))->whereNull('rejected')->values(),
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

    /** Le code d'un tube ou un numéro de laboratoire, exactement : l'UUID de sa demande. */
    private function scanned(string $code): ?string
    {
        $code = strtoupper($code);
        $requestId = LabSample::query()->where('barcode', $code)->value('lab_request_id')
            ?? LabRequest::query()->where('lab_number', $code)->value('id');

        return $requestId ? LabRequest::query()->whereKey($requestId)->value('uuid') : null;
    }

    /** @return array<string, mixed> Les types de prélèvement et de tube actifs, pour la réception. */
    private function sampleOptions(): array
    {
        $tubes = LabTubeType::query()->where('is_active', true)->orderBy('code')->get();

        return [
            'sample_types' => LabSampleType::query()->where('is_active', true)->with('tubeType')->orderBy('name')->get()
                ->map(fn (LabSampleType $type) => [
                    'uuid' => $type->uuid,
                    'name' => $type->name,
                    'instructions' => $type->instructions,
                    'tube_uuid' => $type->tubeType && ! $type->tubeType->trashed() && $type->tubeType->is_active ? $type->tubeType->uuid : null,
                ])->values(),
            'tubes' => $tubes->map(fn (LabTubeType $tube) => [
                'uuid' => $tube->uuid, 'code' => $tube->code, 'name' => $tube->name, 'color' => $tube->cap_color, 'hex' => $tube->color_hex,
            ])->values(),
        ];
    }

    /** @return array<int, string> Les laboratoires extérieurs déjà utilisés, pour ne pas les retaper. */
    private function externalLabs(): array
    {
        return LabRequestItem::query()->whereNotNull('external_lab_name')
            ->distinct()->orderBy('external_lab_name')->limit(30)->pluck('external_lab_name')->all();
    }
}
