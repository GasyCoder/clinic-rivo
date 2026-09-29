<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\FlagCriticalLabResultAction;
use App\Actions\Laboratory\RecordLabResultAction;
use App\Actions\Laboratory\ReturnLabItemAction;
use App\Actions\Laboratory\SaveLabAntibiogramAction;
use App\Actions\Laboratory\SaveLabResultsAction;
use App\Actions\Laboratory\SendLabResultsAction;
use App\Http\Requests\Laboratory\SaveLabAntibiogramRequest;
use App\Http\Requests\Laboratory\SaveLabResultsRequest;
use App\Http\Requests\Laboratory\SendLabResultsRequest;
use App\Http\Requests\RecordLabResultRequest;
use App\Models\LabAntibiogram;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\LabResult;
use App\Models\LabSample;
use App\Models\LabSampleType;
use App\Models\LabTubeType;
use App\Models\RemoteSuperAdmin;
use App\Services\Laboratory\LabPaymentClearance;
use App\Services\Laboratory\LabQueue;
use App\Services\Laboratory\LabRequestPresenter;
use App\Services\Laboratory\LabResultAccess;
use App\Services\Laboratory\LabResultRecipients;
use App\Services\Laboratory\LabWorkbench;
use App\Support\Laboratory\LabEntryOptions;
use App\Support\Paraclinical\ParaclinicalRequestPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-213 — la paillasse : la file par demande, l'espace de saisie d'une
 * demande, les gestes du technicien, la feuille de résultats imprimée.
 *
 * ADR-216 — il n'y a plus de biologiste distinct : le technicien saisit puis
 * envoie les résultats au médecin, et cet envoi les valide. Un médecin qui ouvre
 * la paillasse ou la feuille d'une demande adressée à un confrère passe par la
 * page des résultats, qui demande confirmation (`LabResultAccess`).
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
        LabResultAccess $access,
        LabResultRecipients $recipients,
    ): Response|RedirectResponse {
        $labRequest->load(['items', 'episode.patient', 'requestedBy:id,uuid,name', 'resultsRecipient:id,uuid,name', 'resultsAddressedBy:id,name']);
        $user = $request->user();

        if ($access->sealed($labRequest, $user)) {
            return redirect("/resultats-analyses/{$labRequest->uuid}");
        }

        $items = $labRequest->items->sortBy('id')->map(fn (LabRequestItem $item) => $workbench->present($item))->values();
        $needsCulture = $items->contains(fn (array $item) => collect($item['nodes'])->contains('entry_mode', 'CULTURE'));
        $received = $labRequest->received_at !== null;
        // ADR-215 — sur le portail, les gestes cliniques restent au site : ils
        // sont refusés par `rivo.site-only`, et l'écran les montre verrouillés.
        $atSite = ! $user instanceof RemoteSuperAdmin;
        $gesture = fn (string $permission): bool => $atSite && $user->can($permission);
        $canSample = $gesture('laboratory_samples.create');
        $canSend = $gesture(SendLabResultsAction::PERMISSION);
        $proposed = $recipients->proposedFor($labRequest);

        return Inertia::render('Laboratory/Show', [
            'labRequest' => $presenter->header($labRequest),
            'items' => $items,
            'samples' => $presenter->samples($labRequest),
            // ADR-214 — le contrôle du règlement ne sert qu'avant la réception.
            'payment' => $received ? null : $clearance->for($labRequest),
            'sampleOptions' => ($canSample || $gesture('laboratory_orders.receive')) && ! $labRequest->cancelled_at ? $this->sampleOptions() : null,
            'externalLabs' => $gesture('laboratory_orders.send_out') ? $this->externalLabs() : [],
            'microbiology' => $needsCulture ? $workbench->microbiology() : [],
            // ADR-216 — à qui partent les résultats : déjà adressés, ou proposé d'office.
            'recipient' => [
                'addressed' => $labRequest->resultsAddressed(),
                'uuid' => $labRequest->resultsRecipient?->uuid,
                'name' => $labRequest->resultsRecipient?->name,
                'addressed_at' => $labRequest->results_addressed_at,
                'addressed_by' => $labRequest->resultsAddressedBy?->name,
                'proposed_uuid' => $proposed?->uuid,
            ],
            'recipients' => $canSend && ! $labRequest->cancelled_at ? $recipients->options($labRequest) : [],
            'options' => LabEntryOptions::forScreen(),
            'can' => [
                'enter' => $gesture('laboratory_results.create'),
                'send' => $canSend,
                'flag_critical' => $gesture('laboratory_results.flag_critical'),
                'microbiology' => $user->can('lab_microbiology.view'),
                'receive' => $gesture('laboratory_orders.receive'),
                'sample' => $canSample,
                'reject_sample' => $gesture('laboratory_samples.update'),
                'send_out' => $gesture('laboratory_orders.send_out'),
                'history' => $user->can('laboratory_results.view'),
                'site_only' => ! $atSite,
            ],
        ]);
    }

    public function print(
        Request $request,
        LabRequest $labRequest,
        LabWorkbench $workbench,
        LabRequestPresenter $presenter,
        LabResultAccess $access,
    ): Response|RedirectResponse {
        $labRequest->load(['items', 'episode.patient', 'requestedBy:id,name', 'resultsRecipient:id,name']);

        if ($access->sealed($labRequest, $request->user())) {
            return redirect("/resultats-analyses/{$labRequest->uuid}");
        }

        return Inertia::render('Laboratory/ResultsPrint', [
            'labRequest' => $presenter->header($labRequest),
            'context' => ['mode' => 'lab', 'back_href' => null, 'back_label' => null],
            'sealed' => null,
            'samples' => collect($presenter->samples($labRequest))->whereNull('rejected')->values(),
            // Seul ce qui est rendu s'imprime ; « non envoyé » reste écrit sur la feuille.
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

    public function returnItem(Request $request, LabRequestItem $labRequestItem, ReturnLabItemAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [
            'reason.required' => 'Indiquez pourquoi l’analyse est à refaire.',
            'reason.min' => 'Indiquez pourquoi l’analyse est à refaire.',
        ]);
        $item = $action->execute($labRequestItem, $validated['reason'], $request->user());

        return back()->with('status', "« {$item->catalog_item_name_snapshot} » renvoyée à refaire.");
    }

    /** ADR-216 — envoyer au médecin : le résultat devient définitif. */
    public function send(SendLabResultsRequest $request, LabRequest $labRequest, SendLabResultsAction $action): RedirectResponse
    {
        $sent = $action->execute(
            $labRequest,
            $request->validated('items'),
            $request->validated('recipient_uuid'),
            (bool) $request->validated('to_nobody', false),
            $request->user(),
        );

        $what = $sent['count'] === 1 ? 'Une analyse envoyée' : "{$sent['count']} analyses envoyées";

        return back()->with('status', $sent['recipient']
            ? "{$what} à {$sent['recipient']->name} : le résultat est définitif."
            : "{$what}, sans médecin destinataire (patient externe) : le résultat est définitif.");
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

        return back()->with('status', 'Résultat enregistré : envoyez-le au médecin pour le rendre définitif.');
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
