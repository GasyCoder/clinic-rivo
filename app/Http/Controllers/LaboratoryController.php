<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\ArchiveLabRequestAction;
use App\Actions\Laboratory\BulkLabRequestAction;
use App\Actions\Laboratory\EditLabRequestAction;
use App\Actions\Laboratory\FlagCriticalLabResultAction;
use App\Actions\Laboratory\LabRequestGuard;
use App\Actions\Laboratory\ReceiveLabRequestAction;
use App\Actions\Laboratory\RecordLabResultAction;
use App\Actions\Laboratory\ResetLabResultsAction;
use App\Actions\Laboratory\CompleteLabItemAction;
use App\Actions\Laboratory\ReopenLabItemAction;
use App\Actions\Laboratory\ReturnLabItemAction;
use App\Actions\Laboratory\SaveLabAntibiogramAction;
use App\Actions\Laboratory\SaveLabResultsAction;
use App\Actions\Laboratory\SendLabResultsAction;
use App\Actions\Laboratory\TrashLabRequestAction;
use App\Http\Requests\Laboratory\SaveLabAntibiogramRequest;
use App\Http\Requests\Laboratory\SaveLabResultsRequest;
use App\Http\Requests\Laboratory\SendLabResultsRequest;
use App\Http\Requests\RecordLabResultRequest;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
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
        // ADR-217 — sans vue choisie, ce qui est à traiter ; une ancienne vue (« to_receive ») y mène aussi.
        $view = in_array($requested, LabQueue::VIEWS, true) ? $requested : 'to_do';

        $requests = $queue->query($view)
            ->tap($refine)
            ->with([
                'items:id,uuid,lab_request_id,catalog_item_name_snapshot,catalog_item_code_snapshot,status,resulted_at,validated_at,sent_at,sent_out_at,external_lab_name,billable_item_id',
                'items.results:id,lab_request_item_id,is_critical,interpretation',
                'episode:id,uuid,episode_number,patient_id,priority',
                'episode.patient:id,uuid,patient_number,first_name,last_name,birth_date,declared_age,sex',
                'requestedBy:id,name',
                'receivedBy:id,name',
                ...LabPaymentClearance::RELATIONS,
            ])
            ->withCount(['samples' => fn ($samples) => $samples->whereNull('rejected_at')])
            ->orderByRaw('requested_at IS NULL')
            ->when(in_array($view, ['validated', 'all', 'archived'], true), fn ($query) => $query->latest('requested_at'), fn ($query) => $query->oldest('requested_at'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LabRequest $labRequest) => [
                'uuid' => $labRequest->uuid,
                'lab_number' => $labRequest->lab_number,
                'state' => LabQueue::stateOf($labRequest),
                'action' => LabQueue::actionOf($labRequest),
                // ADR-220 — ce que la sélection peut faire de cette ligne, jugé par le serveur.
                'archived' => $labRequest->isLabArchived(),
                'archived_at' => $labRequest->lab_archived_at,
                'archivable' => ! $labRequest->isLabArchived() && LabRequestGuard::finished($labRequest),
                'trashable' => ! LabRequestGuard::anySent($labRequest),
                'started_by' => $labRequest->receivedBy?->name,
                'origin' => ParaclinicalRequestPresenter::origin($labRequest),
                'requested_at' => $labRequest->requested_at,
                'requested_by' => $labRequest->requestedBy?->name,
                'emergency' => $labRequest->episode?->priority?->value === 'EMERGENCY',
                'patient' => LabRequestPresenter::patient($labRequest->episode->patient),
                'episode_number' => $labRequest->episode->episode_number,
                'samples_count' => $labRequest->samples_count,
                // ADR-217 — le règlement se lit sur la ligne, il ne retient plus rien.
                'payment' => collect($clearance->for($labRequest))->only(['cleared', 'exemption', 'exemption_label', 'due_count', 'unbilled_count'])->all(),
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
            // ADR-215 — sur le portail, « Traiter » reste un geste du site.
            'canStart' => ! $request->user() instanceof RemoteSuperAdmin && ReceiveLabRequestAction::canTakeUp($request->user()),
            // ADR-220 — archiver et mettre à la corbeille se font aussi depuis le portail.
            'manage' => [
                'archive' => $request->user()->can(ArchiveLabRequestAction::PERMISSION),
                'trash' => $request->user()->can(TrashLabRequestAction::PERMISSION),
                'max' => BulkLabRequestAction::MAX,
            ],
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

        // ADR-220 — une analyse se retire tant qu'elle n'est pas envoyée au médecin.
        $items = $labRequest->items->sortBy('id')->map(fn (LabRequestItem $item) => [
            ...$workbench->present($item),
            'removable' => $item->sent_at === null,
        ])->values();
        $needsCulture = $items->contains(fn (array $item) => collect($item['nodes'])->contains('entry_mode', 'CULTURE'));
        // ADR-215 — sur le portail, les gestes cliniques restent au site : ils
        // sont refusés par `rivo.site-only`, et l'écran les montre verrouillés.
        $atSite = ! $user instanceof RemoteSuperAdmin;
        $gesture = fn (string $permission): bool => $atSite && $user->can($permission);
        $canSample = $gesture('laboratory_samples.create');
        $canSend = $gesture(SendLabResultsAction::PERMISSION);
        $proposed = $recipients->proposedFor($labRequest);
        $canEdit = $gesture(EditLabRequestAction::PERMISSION) && ! $labRequest->cancelled_at;

        return Inertia::render('Laboratory/Show', [
            'labRequest' => $presenter->header($labRequest),
            'items' => $items,
            'samples' => $presenter->samples($labRequest),
            // ADR-217 — le règlement s'affiche pour information, il ne retient pas la saisie.
            'payment' => $clearance->for($labRequest),
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
                // Amendement ADR-216 du 2026-09-29 (ter) — un, plusieurs ou tous.
                'names' => $labRequest->recipientNames(),
                'proposed_uuids' => $recipients->proposedUuidsFor($labRequest),
            ],
            'recipients' => $canSend && ! $labRequest->cancelled_at ? $recipients->options($labRequest) : [],
            'options' => LabEntryOptions::forScreen(),
            // ADR-220 — les analyses que l'on peut ajouter : celles du laboratoire, pas déjà demandées.
            'addableAnalyses' => $canEdit && ! $labRequest->isLabArchived()
                ? CatalogItem::query()
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Laboratory->value)
                    ->whereNotIn('id', $labRequest->items->pluck('catalog_item_id'))
                    ->orderBy('name')
                    ->get(['uuid', 'code', 'name'])
                : [],
            'manage' => [
                'archived' => $labRequest->isLabArchived(),
                'archived_at' => $labRequest->lab_archived_at,
                'archivable' => ! $labRequest->isLabArchived() && LabRequestGuard::finished($labRequest),
                'trashable' => ! LabRequestGuard::anySent($labRequest),
                'edit' => $canEdit,
                'edit_locked' => ! $atSite && $user->can(EditLabRequestAction::PERMISSION),
                'archive' => $user->can(ArchiveLabRequestAction::PERMISSION) && ! $labRequest->cancelled_at,
                'trash' => $user->can(TrashLabRequestAction::PERMISSION) && ! $labRequest->cancelled_at,
            ],
            'can' => [
                'enter' => $gesture('laboratory_results.create'),
                'send' => $canSend,
                // Amendement du 2026-09-29 — « Renvoyer à refaire » a son droit propre ;
                // sans lui, le bouton reste visible et verrouillé, avec le droit à demander.
                'return' => $gesture(ReturnLabItemAction::PERMISSION),
                'flag_critical' => $gesture('laboratory_results.flag_critical'),
                'microbiology' => $user->can('lab_microbiology.view'),
                'receive' => $gesture('laboratory_orders.receive'),
                'start' => $atSite && ReceiveLabRequestAction::canTakeUp($user),
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
        $labRequest->load(['items.results', 'episode.patient', 'requestedBy:id,name', 'resultsRecipient:id,name']);

        if ($access->sealed($labRequest, $request->user())) {
            return redirect("/resultats-analyses/{$labRequest->uuid}");
        }

        return Inertia::render('Laboratory/ResultsPrint', [
            'labRequest' => $presenter->header($labRequest),
            'context' => ['mode' => 'lab', 'back_href' => null, 'back_label' => null],
            'sealed' => null,
            'samples' => collect($presenter->samples($labRequest))->whereNull('rejected')->values(),
            // ADR-218 — les analyses du compte rendu PDF : tout ce qui porte un résultat,
            // envoyé ou non (« provisoire » est alors écrit sur le PDF).
            'items' => $labRequest->items->sortBy('id')
                ->filter(fn (LabRequestItem $item) => $item->resulted_at !== null
                    || $item->results->contains(fn ($result) => ! $result->isBlank()))
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

    public function resetResults(Request $request, LabRequestItem $labRequestItem, ResetLabResultsAction $action): RedirectResponse
    {
        $action->execute($labRequestItem, $request->user());

        return back()->with('success', 'Saisie réinitialisée : l’analyse repart de zéro.');
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

    /** Amendement ADR-216 du 2026-09-29 — terminer une analyse : elle attend ensuite son envoi. */
    public function completeItem(Request $request, LabRequestItem $labRequestItem, CompleteLabItemAction $action): RedirectResponse
    {
        $item = $action->execute($labRequestItem, $request->user());

        return back()->with('status', "« {$item->catalog_item_name_snapshot} » terminée : elle peut partir au médecin.");
    }

    public function reopenItem(Request $request, LabRequestItem $labRequestItem, ReopenLabItemAction $action): RedirectResponse
    {
        $item = $action->execute($labRequestItem, $request->user());

        return back()->with('status', "Saisie de « {$item->catalog_item_name_snapshot} » rouverte.");
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
            $request->recipientUuids(),
            (bool) $request->validated('to_nobody', false),
            $request->user(),
        );

        $what = $sent['count'] === 1 ? 'Une analyse envoyée' : "{$sent['count']} analyses envoyées";
        $names = $sent['recipients']->pluck('name')->implode(', ');

        return back()->with('status', $names !== ''
            ? "{$what} à {$names} : le résultat est définitif."
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
