<?php

namespace App\Http\Controllers;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Care\CancelCareConsumableRequestAction;
use App\Actions\Care\CompleteCareAndOrientToMedicineAction;
use App\Actions\Care\MarkCareOrderItemNotPerformedAction;
use App\Actions\Care\ReleaseCareOrientationAction;
use App\Actions\Care\SaveAndCompleteCareAction;
use App\Actions\Care\SaveCareRecordAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Http\Requests\Care\CancelCareConsumableRequestRequest;
use App\Http\Requests\Care\CompleteCareOrientationRequest;
use App\Http\Requests\Care\SaveCareRecordDraftRequest;
use App\Http\Requests\MarkCareOrderItemNotPerformedRequest;
use App\Http\Requests\UpdateCareRecordRequest;
use App\Models\AllergenReference;
use App\Models\CareConsumableRequest;
use App\Models\CareOrder;
use App\Models\CareOrderItem;
use App\Models\CareRecordDraft;
use App\Models\CatalogItem;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Services\Care\CareConsumableDirectory;
use App\Services\Care\CareRecordReadModel;
use App\Support\BloodPressureAssessment;
use App\Support\BmiAssessment;
use App\Support\CareHandlerGuard;
use App\Support\CareRequestSummary;
use App\Support\CareWorkflow;
use App\Support\EpisodeQueuePresenter;
use App\Support\HeartRateAssessment;
use App\Support\OxygenSaturationAssessment;
use App\Support\TemperatureAssessment;
use App\Support\VitalSignAgeReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CareController extends Controller
{
    /** Les files de la page Soins, dans l'ordre des onglets (ADR-124). */
    private const QUEUE_FILTERS = ['active', 'waiting_doctor'];

    /**
     * Depuis quand chaque patient orienté attend le médecin (ADR-124) : la
     * plus récente orientation Médecine encore en attente. Une seule requête
     * pour toute la page.
     *
     * @param  Collection<int, EpisodeOrientation>  $orientations
     * @param  array<int, int>  $medicineNumbers  n° d'ordre Médecine, indexé par orientation Médecine
     * @return array<int, array{state: string, label: string, since: ?string, queue_number: ?int}>
     */
    private function medicineStates($orientations, array $medicineNumbers): array
    {
        $episodeIds = $orientations->pluck('episode_id')->unique()->values();

        if ($episodeIds->isEmpty()) {
            return [];
        }

        return EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->whereIn('episode_id', $episodeIds)
            ->where('status', EpisodeOrientationStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->keyBy('episode_id')
            ->map(fn (EpisodeOrientation $medicine) => [
                'state' => $medicine->status->value,
                'label' => 'En attente du médecin',
                'since' => $medicine->oriented_at?->toIso8601String(),
                'queue_number' => $medicineNumbers[$medicine->getKey()] ?? null,
            ])
            ->all();
    }

    public function index(Request $request, EpisodeQueuePresenter $presenter): Response
    {
        $filter = in_array($request->query('filter'), self::QUEUE_FILTERS, true)
            ? (string) $request->query('filter')
            : 'active';
        $search = trim((string) $request->query('q', ''));
        $priority = in_array($request->query('priority'), ['emergency', 'normal'], true)
            ? (string) $request->query('priority')
            : null;

        $baseQuery = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Care->value)
            ->whereHas('episode', fn ($query) => $query->where('status', 'OPEN'))
            // A patient is never truly deletable (ADR-010) — under normal
            // operation this can never fail to match. It only guards against
            // data corruption bypassing Eloquent entirely (e.g. a raw
            // TRUNCATE on patients), so an orphaned row disappears from the
            // queue instead of fataling the whole page.
            ->whereHas('episode.patient');

        // ADR-124 : la file Soins ne montre que deux choses — les patients à
        // prendre aux Soins, et ceux que les Soins ont orientés vers Médecine et
        // qui attendent encore le médecin. Dès que le médecin les a accueillis, ou
        // qu'ils ne vont pas vers un médecin, ils ne sont plus ici : ils vivent
        // dans le module Patients.
        $scopeToFilter = fn ($query, string $value) => match ($value) {
            'waiting_doctor' => $query->where('status', EpisodeOrientationStatus::Completed->value)
                ->whereHas('episode.orientations', fn ($orientation) => $orientation
                    ->where('destination_module', CatalogModule::Medicine->value)
                    ->where('status', EpisodeOrientationStatus::Pending->value)),
            default => $query->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ]),
        };

        $counts = collect(self::QUEUE_FILTERS)
            ->mapWithKeys(fn (string $value) => [$value => $scopeToFilter(clone $baseQuery, $value)->count()])
            ->all();

        $scopeToCurrentStatus = fn ($query) => $scopeToFilter($query, $filter);
        $priorityCounts = [
            'all' => $scopeToCurrentStatus((clone $baseQuery))->count(),
            'emergency' => $scopeToCurrentStatus((clone $baseQuery))
                ->whereHas('episode', fn ($q) => $q->where('priority', EpisodePriority::Emergency->value))
                ->count(),
            'normal' => $scopeToCurrentStatus((clone $baseQuery))
                ->whereHas('episode', fn ($q) => $q->where('priority', '!=', EpisodePriority::Emergency->value))
                ->count(),
        ];

        $orientations = $baseQuery
            ->with([
                'episode.patient',
                'episode.billableItems',
                'episode.serviceRequests',
                'acceptedBy:id,name',
            ])
            ->tap(fn ($query) => $scopeToFilter($query, $filter))
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('episode', function ($episodeQuery) use ($search): void {
                    $episodeQuery->where('episode_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', function ($patientQuery) use ($search): void {
                            $patientQuery->where('patient_number', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($priority === 'emergency', fn ($query) => $query
                ->whereHas('episode', fn ($episodeQuery) => $episodeQuery
                    ->where('priority', EpisodePriority::Emergency->value)))
            ->when($priority === 'normal', fn ($query) => $query
                ->whereHas('episode', fn ($episodeQuery) => $episodeQuery
                    ->where('priority', '!=', EpisodePriority::Emergency->value)))
            // Only a still fast-tracked Emergency (Médecine hasn't yet
            // completed a first consultation for it) is pinned ahead of
            // arrival order — matches EpisodeQueuePresenter::isQueueEligible
            // below. Once eligible, first arrived is always first in the
            // list, emergency or not.
            ->orderByRaw(EpisodeQueuePresenter::PIN_UNSEEN_EMERGENCY_SQL)
            // Oriented (completed) history reads best newest-first; the
            // active/waiting queue must read oldest-first — first arrived,
            // first served — to match the queue numbers below.
            // Du plus ancien au plus récent : premier arrivé, premier servi — pour la
            // file comme pour ceux qui attendent le médecin depuis le plus longtemps.
            ->when(
                $filter === 'waiting_doctor',
                // Dans l'ordre de la file Médecine : ce n° d'ordre est le sien.
                fn ($query) => $query->orderByRaw("(SELECT MIN(eo_doctor.oriented_at) FROM episode_orientations eo_doctor WHERE eo_doctor.episode_id = episode_orientations.episode_id AND eo_doctor.destination_module = 'MEDICINE' AND eo_doctor.status = 'PENDING')"),
                fn ($query) => $query->orderBy('oriented_at'),
            )
            ->paginate(20)
            ->withQueryString();

        // Queue numbers only make sense for people still waiting, never for
        // the already-oriented history.
        $queueNumbers = $filter === 'active'
            ? $presenter->assignQueueNumbers($orientations->getCollection())
            : [];
        $doctors = $filter === 'waiting_doctor'
            ? $this->medicineStates($orientations->getCollection(), $presenter->medicineQueueNumbers())
            : [];
        // ADR-118 : ce que chaque orientation Soins demandée par le médecin
        // contient (demandeur, suite décidée, actes). Sans cela deux demandes
        // du même passage se lisaient comme deux passages identiques.
        $careRequests = CareRequestSummary::forOrientations(
            $orientations->getCollection()->map(fn (EpisodeOrientation $orientation) => $orientation->getKey()),
            $request->user()->can('care_orders.view'),
        );
        $orientations->through(fn (EpisodeOrientation $orientation) => [
            ...$presenter->present($orientation, $queueNumbers[$orientation->getKey()] ?? null),
            'care_request' => $careRequests[$orientation->getKey()] ?? null,
            'doctor' => $doctors[$orientation->episode_id] ?? null,
        ]);

        return Inertia::render('Care/Index', [
            'orientations' => $orientations,
            'counts' => $counts,
            'priorityCounts' => $priorityCounts,
            'filter' => $filter,
            'search' => $search,
            'priority' => $priority,
        ]);
    }

    public function show(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        EpisodeQueuePresenter $presenter,
        BmiAssessment $bmiAssessment,
        BloodPressureAssessment $bloodPressureAssessment,
        HeartRateAssessment $heartRateAssessment,
        OxygenSaturationAssessment $oxygenSaturationAssessment,
        TemperatureAssessment $temperatureAssessment,
        CareRecordReadModel $careRecordReadModel,
        CareConsumableDirectory $consumables,
        CareWorkflow $careWorkflow,
    ): Response {
        $episodeOrientation->load([
            'episode.patient',
            'episode.billableItems',
            'episode.serviceRequests',
            'episode.careRecord.creator:id,name',
            'episode.careRecord.updater:id,name',
            'episode.careRecord.procedures.performer:id,name',
            'acceptedBy:id,name',
        ]);

        abort_unless($episodeOrientation->destination_module === CatalogModule::Care, 404);
        abort_unless($episodeOrientation->episode->patient, 404, 'Le dossier patient de ce passage est introuvable.');

        $record = $episodeOrientation->episode->careRecord;
        $patientAge = $this->patientAgeAtEpisode($episodeOrientation);
        $canViewVitals = $request->user()->can('vitals.view');
        $canViewAllergies = $request->user()->can('patients.medical_history.view');
        $canManageAllergies = $request->user()->can('patients.medical_history.manage');

        if ($canViewAllergies) {
            $episodeOrientation->episode->patient->load('allergies');
        }

        // Terminer les soins et transférer vers Médecine restent réservés à
        // la personne qui a pris le patient en charge (ADR-085).
        $isHandler = CareHandlerGuard::isHandledBy($episodeOrientation, $request->user());
        // Corriger la fiche, en revanche, reste possible après le transfert
        // et pour tout compte Soins autorisé — décision du 2026-09-15, qui
        // amende l'ADR-085 : une erreur de saisie doit pouvoir être
        // rectifiée même quand le soignant a fini son service.
        $canEdit = CareHandlerGuard::isEditable($episodeOrientation)
            && $request->user()->can($record ? 'care.update' : 'care.create');
        $canEditVitals = $canEdit
            && $request->user()->can($record ? 'vitals.update' : 'vitals.create');
        $canViewCareOrders = $request->user()->can('care_orders.view');
        // ADR-072 — declaring a consumable is a write on the visit, so it
        // follows the same "orientation still in progress" rule as any
        // other Soins entry, on top of its own permission.
        $canViewConsumables = $request->user()->can('care_consumables.view');
        $canRequestConsumables = $canEdit && $request->user()->can('care_consumables.request');

        // Same fact CompleteCareAndOrientToMedicineAction::settleAdministrativelyIfPathwayComplete()
        // checks before settling — surfaced read-only so Vue can label the
        // completion button correctly without re-deciding the rule itself.
        $hasActiveMedicineOrientation = EpisodeOrientation::query()
            ->where('active_key', $episodeOrientation->episode_id.':MEDICINE')
            ->exists();

        $latestDiagnosis = $canViewAllergies || $canEdit
            ? Diagnosis::query()
                ->whereHas('consultation', fn ($query) => $query->where('episode_id', $episodeOrientation->episode_id))
                ->where('type', DiagnosisType::Final->value)
                ->whereDoesntHave('cancellation')
                ->with(['recordedBy:id,name', 'consultation.doctor:id,name'])
                ->latest('created_at')
                ->first()
            : null;

        return Inertia::render('Care/Show', [
            'orientation' => $presenter->present($episodeOrientation),
            'hasActiveMedicineOrientation' => $hasActiveMedicineOrientation,
            // ADR-166 — la règle même de l'action de fin des Soins : Médecine a
            // déjà ce patient, donc ni « transmettre » ni « terminer aux Soins »
            // n'a de choix à proposer.
            'medicineAlreadyInvolved' => $careWorkflow->medicineAlreadyInvolved($episodeOrientation->episode),
            'completionReason' => $episodeOrientation->completion_reason,
            'latestDiagnosis' => $latestDiagnosis ? [
                'description' => $latestDiagnosis->description,
                'doctor' => $latestDiagnosis->consultation->doctor?->name ?? $latestDiagnosis->recordedBy?->name,
                'recorded_at' => $latestDiagnosis->created_at,
            ] : null,
            'careRecord' => $careRecordReadModel->present($record, $request->user()),
            'bmiReference' => $canViewVitals ? $bmiAssessment->reference($patientAge) : null,
            'bloodPressureReference' => $canViewVitals ? $bloodPressureAssessment->reference($patientAge) : null,
            'heartRateReference' => $canViewVitals ? $heartRateAssessment->reference($patientAge) : null,
            'oxygenSaturationReference' => $canViewVitals ? $oxygenSaturationAssessment->reference() : null,
            'vitalPlausibility' => $canViewVitals ? VitalSignAgeReference::plausibility($patientAge) : null,
            'temperatureReference' => $canViewVitals ? $temperatureAssessment->reference($patientAge) : null,
            'patientAllergies' => $canViewAllergies
                ? $episodeOrientation->episode->patient->allergies->map(fn ($allergy) => [
                    'uuid' => $allergy->uuid,
                    'substance' => $allergy->substance,
                    'reaction' => $allergy->reaction,
                    'severity' => $allergy->severity?->value,
                ])->values()
                : [],
            'allergenReference' => $canManageAllergies
                ? AllergenReference::query()
                    ->where('active', true)
                    ->orderBy('category')
                    ->orderBy('name')
                    ->get(['uuid', 'code', 'name', 'category'])
                    ->map(fn (AllergenReference $reference) => [
                        'uuid' => $reference->uuid,
                        'code' => $reference->code,
                        'name' => $reference->name,
                        'category' => $reference->category->value,
                        'category_label' => $reference->category->label(),
                    ])
                    ->values()
                : [],
            'procedureCatalog' => CatalogItem::query()
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Care->value)
                // ADR-072 — the material usually consumed by the act, so the
                // nurse gets a coherent pre-selection instead of hunting for
                // it. Only exposed to an account that may actually declare
                // material; it is a suggestion, never a commitment.
                ->when($canRequestConsumables, fn ($query) => $query->with([
                    'defaultConsumables.medicine' => fn ($medicine) => $medicine
                        ->where('active', true)
                        ->with('catalogItem:id,code,name,unit'),
                ]))
                ->orderBy('name')
                ->get([
                    'id', 'uuid', 'code', 'name', 'unit',
                    'care_requires_allergy_check', 'care_recommends_vitals',
                ])
                ->map(fn (CatalogItem $item) => [
                    'uuid' => $item->uuid,
                    'code' => $item->code,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'care_requires_allergy_check' => $item->care_requires_allergy_check,
                    'care_recommends_vitals' => $item->care_recommends_vitals,
                    'default_consumables' => $canRequestConsumables
                        ? $item->defaultConsumables
                            ->filter(fn ($row) => $row->medicine && $row->medicine->catalogItem)
                            ->map(fn ($row) => [
                                'medicine_uuid' => $row->medicine->uuid,
                                'code' => $row->medicine->catalogItem->code,
                                'name' => $row->medicine->catalogItem->name,
                                'unit' => $row->medicine->catalogItem->unit,
                                'quantity' => $row->default_quantity,
                            ])
                            ->values()
                        : [],
                ]),
            // Typing in progress, restored after a reload. Scoped to this
            // account: a nurse never inherits another's unvalidated entry.
            'careRecordDraft' => $canEdit
                ? CareRecordDraft::query()
                    ->where('episode_orientation_id', $episodeOrientation->getKey())
                    ->where('created_by', $request->user()->getKey())
                    ->first(['payload', 'updated_at'])
                    ?->only(['payload', 'updated_at'])
                : null,
            'consumableCatalog' => $canRequestConsumables
                ? $consumables->selectableConsumables()
                : [],
            'consumableRequests' => $canViewConsumables
                ? $consumables->forOrientation($episodeOrientation->getKey())
                : [],
            'careOrders' => $canViewCareOrders
                ? CareOrder::query()
                    ->where('care_orientation_id', $episodeOrientation->getKey())
                    ->with(['items.careRecordProcedures', 'items.cancelledBy:id,name', 'requestedBy:id,name', 'consultation'])
                    ->latest('ordered_at')
                    ->get()
                    ->map(fn (CareOrder $careOrder) => [
                        'uuid' => $careOrder->uuid,
                        'requested_by' => $careOrder->requestedBy?->name,
                        'consultation_number' => $careOrder->consultation_id,
                        'instructions' => $careOrder->instructions,
                        'requires_return_to_medicine' => $careOrder->requires_return_to_medicine,
                        'status' => $careOrder->displayStatus(),
                        'has_unresolved_items' => $careOrder->hasUnresolvedItems(),
                        'ordered_at' => $careOrder->ordered_at,
                        'completed_at' => $careOrder->completed_at,
                        'items' => $careOrder->items->map(fn (CareOrderItem $item) => [
                            'uuid' => $item->uuid,
                            'name' => $item->catalog_item_name_snapshot,
                            'code' => $item->catalog_item_code_snapshot,
                            'quantity' => $item->quantity,
                            'realized_quantity' => $item->realizedQuantity(),
                            'remaining_quantity' => $item->remainingQuantity(),
                            'instructions' => $item->instructions,
                            'not_performed_at' => $item->not_performed_at,
                            'not_performed_reason' => $item->not_performed_reason,
                            'cancelled_at' => $item->cancelled_at,
                            'cancelled_by' => $item->cancelledBy?->name,
                            'cancel_reason' => $item->cancel_reason,
                            'resolved' => $item->isResolved(),
                        ])->values(),
                    ])->values()
                : [],
            'capabilities' => [
                'can_view_vitals' => $canViewVitals,
                'can_view_allergies' => $canViewAllergies,
                'can_manage_allergies' => $canManageAllergies,
                'can_edit' => $canEdit,
                'can_edit_vitals' => $canEditVitals,
                'can_complete' => $isHandler
                    && $request->user()->can('care.complete'),
                'handled_by_other' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress
                    && ! $isHandler,
                'can_view_care_orders' => $canViewCareOrders,
                'can_view_consumables' => $canViewConsumables,
                'can_request_consumables' => $canRequestConsumables,
                'can_cancel_consumables' => $canViewConsumables
                    && $request->user()->can('care_consumables.cancel'),
            ],
        ]);
    }

    public function saveRecord(
        UpdateCareRecordRequest $request,
        EpisodeOrientation $episodeOrientation,
        SaveCareRecordAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation,
            $request->safe()->except(['orient_to_medicine', 'care_outcome', 'care_finish_reason']),
            $request->user(),
            $request->destination(),
        );

        return redirect()->route('care.orientations.show', $episodeOrientation)
            ->with('status', 'Fiche de soins enregistrée.');
    }

    public function saveAndComplete(
        UpdateCareRecordRequest $request,
        EpisodeOrientation $episodeOrientation,
        SaveAndCompleteCareAction $action,
    ): RedirectResponse {
        $completed = $action->execute(
            $episodeOrientation,
            $request->safe()->except(['orient_to_medicine', 'care_outcome', 'care_finish_reason']),
            $request->user(),
            $request->destination(),
            $request->validated('care_finish_reason'),
        );

        return redirect()->route('care.index')->with('status', $this->completionMessage($completed, saved: true));
    }

    public function markCareOrderItemNotPerformed(
        MarkCareOrderItemNotPerformedRequest $request,
        EpisodeOrientation $episodeOrientation,
        CareOrderItem $careOrderItem,
        MarkCareOrderItemNotPerformedAction $action,
    ): RedirectResponse {
        $action->execute($careOrderItem, $request->validated('reason'), $request->user());

        return redirect()->route('care.orientations.show', $episodeOrientation)
            ->with('status', 'Acte marqué non réalisé.');
    }

    /**
     * Autosaved typing, so a reload never loses what the nurse entered.
     * Returns no Inertia response: the browser calls this in the background
     * and must not have its page re-rendered mid-typing.
     */
    public function saveDraft(
        SaveCareRecordDraftRequest $request,
        EpisodeOrientation $episodeOrientation,
    ): JsonResponse {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Care, 404);
        // Le brouillon suit la fiche : il reste possible après le transfert
        // vers Médecine, et pour tout compte Soins autorisé (décision du
        // 2026-09-15). Il reste rattaché à son auteur : sur un poste
        // partagé, personne ne récupère la saisie d'un collègue (ADR-073).
        abort_unless(CareHandlerGuard::isEditable($episodeOrientation), 409);

        $draft = CareRecordDraft::query()->updateOrCreate(
            [
                'episode_orientation_id' => $episodeOrientation->getKey(),
                'created_by' => $request->user()->getKey(),
            ],
            ['payload' => $request->draftPayload()],
        );

        return response()->json(['saved_at' => $draft->updated_at->toIso8601String()]);
    }

    /** The nurse explicitly discards their entry. */
    public function discardDraft(
        Request $request,
        EpisodeOrientation $episodeOrientation,
    ): RedirectResponse {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Care, 404);

        CareRecordDraft::query()
            ->where('episode_orientation_id', $episodeOrientation->getKey())
            ->where('created_by', $request->user()->getKey())
            ->delete();

        return redirect()
            ->route('care.orientations.show', $episodeOrientation)
            ->with('status', 'Saisie en cours annulée.');
    }

    public function cancelConsumables(
        CancelCareConsumableRequestRequest $request,
        EpisodeOrientation $episodeOrientation,
        CareConsumableRequest $careConsumableRequest,
        CancelCareConsumableRequestAction $action,
    ): RedirectResponse {
        abort_unless(
            $careConsumableRequest->care_orientation_id === $episodeOrientation->getKey(),
            404,
        );

        $action->execute($careConsumableRequest, $request->validated('reason'), $request->user());

        return redirect()
            ->route('care.orientations.show', $episodeOrientation)
            ->with('status', 'Demande de consommables annulée. La Pharmacie ne la verra plus dans sa file.');
    }

    public function accept(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        AcceptCareOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('care.orientations.show', $episodeOrientation)
            ->with('status', 'Patient pris en charge aux Soins.');
    }

    /** ADR-122 : remettre en file, à sa place, un patient pris en charge par erreur. */
    public function release(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        ReleaseCareOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('care.index')
            ->with('status', 'Patient remis en file, à sa place.');
    }

    public function complete(
        CompleteCareOrientationRequest $request,
        EpisodeOrientation $episodeOrientation,
        CompleteCareAndOrientToMedicineAction $action,
    ): RedirectResponse {
        $completed = $action->execute(
            $episodeOrientation,
            $request->user(),
            $request->destination(),
            $request->validated('care_finish_reason'),
        );

        return back()->with('status', $this->completionMessage($completed, saved: false));
    }

    /**
     * Ce que devient le patient, dit par ce qui a réellement été enregistré :
     * une orientation Médecine active, ou la fin aux Soins.
     */
    private function completionMessage(EpisodeOrientation $completed, bool $saved): string
    {
        $prefix = $saved ? 'Actes enregistrés' : 'Soins terminés';

        $waitingForDoctor = $completed->episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->whereIn('status', [EpisodeOrientationStatus::Pending->value, EpisodeOrientationStatus::InProgress->value])
            ->exists();

        if ($waitingForDoctor) {
            return $prefix.'. Le patient est maintenant en attente en Médecine.';
        }

        return $completed->completion_reason
            ? $prefix.'. Le patient est terminé aux Soins, sans passer en Médecine.'
            : $prefix.' et prise en charge terminée.';
    }

    public function completeAndOrient(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        CompleteCareAndOrientToMedicineAction $action,
    ): RedirectResponse {
        $action->executeForUnknownNeed($episodeOrientation, $request->user());

        return back()->with('status', 'Évaluation terminée. Le patient est orienté vers Médecine.');
    }

    private function patientAgeAtEpisode(EpisodeOrientation $orientation): ?int
    {
        $patient = $orientation->episode->patient;

        if ($patient->birth_date) {
            $referenceDate = $orientation->episode->started_at ?? now();

            if ($patient->birth_date->isAfter($referenceDate)) {
                return null;
            }

            return (int) $patient->birth_date->diffInYears($referenceDate);
        }

        return $patient->declared_age;
    }
}
