<?php

namespace App\Http\Controllers;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Care\CompleteCareAndOrientToMedicineAction;
use App\Actions\Care\MarkCareOrderItemNotPerformedAction;
use App\Actions\Care\SaveAndCompleteCareAction;
use App\Actions\Care\SaveCareRecordAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Http\Requests\MarkCareOrderItemNotPerformedRequest;
use App\Http\Requests\UpdateCareRecordRequest;
use App\Models\AllergenReference;
use App\Models\CareOrder;
use App\Models\CareOrderItem;
use App\Models\CatalogItem;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Services\Care\CareRecordReadModel;
use App\Support\BloodPressureAssessment;
use App\Support\BmiAssessment;
use App\Support\EpisodeQueuePresenter;
use App\Support\HeartRateAssessment;
use App\Support\OxygenSaturationAssessment;
use App\Support\TemperatureAssessment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CareController extends Controller
{
    public function index(Request $request, EpisodeQueuePresenter $presenter): Response
    {
        $filter = in_array($request->query('filter'), ['active', 'oriented'], true)
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

        $counts = [
            'active' => (clone $baseQuery)->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])->count(),
            'oriented' => (clone $baseQuery)
                ->where('status', EpisodeOrientationStatus::Completed->value)
                ->count(),
        ];

        $scopeToCurrentStatus = fn ($query) => $query->when(
            $filter === 'oriented',
            fn ($q) => $q->where('status', EpisodeOrientationStatus::Completed->value),
            fn ($q) => $q->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ]),
        );
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
            ->when(
                $filter === 'oriented',
                fn ($query) => $query->where('status', EpisodeOrientationStatus::Completed->value),
                fn ($query) => $query->whereIn('status', [
                    EpisodeOrientationStatus::Pending->value,
                    EpisodeOrientationStatus::InProgress->value,
                ]),
            )
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
            ->when(
                $filter === 'oriented',
                fn ($query) => $query->orderByDesc('completed_at'),
                fn ($query) => $query->orderBy('oriented_at'),
            )
            ->paginate(20)
            ->withQueryString();

        // Queue numbers only make sense for people still waiting, never for
        // the already-oriented history.
        $queueNumbers = $filter === 'oriented'
            ? []
            : $presenter->assignQueueNumbers($orientations->getCollection());
        $orientations->through(fn (EpisodeOrientation $orientation) => $presenter->present(
            $orientation,
            $queueNumbers[$orientation->getKey()] ?? null,
        ));

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

        $canEdit = $episodeOrientation->status === EpisodeOrientationStatus::InProgress
            && $request->user()->can($record ? 'care.update' : 'care.create');
        $canEditVitals = $canEdit
            && $request->user()->can($record ? 'vitals.update' : 'vitals.create');
        $canViewCareOrders = $request->user()->can('care_orders.view');

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
            'latestDiagnosis' => $latestDiagnosis ? [
                'description' => $latestDiagnosis->description,
                'doctor' => $latestDiagnosis->consultation->doctor?->name ?? $latestDiagnosis->recordedBy?->name,
                'recorded_at' => $latestDiagnosis->created_at,
            ] : null,
            'careRecord' => $careRecordReadModel->present($record, $request->user()),
            'bmiReference' => $canViewVitals ? $bmiAssessment->reference($patientAge) : null,
            'bloodPressureReference' => $canViewVitals ? $bloodPressureAssessment->reference() : null,
            'heartRateReference' => $canViewVitals ? $heartRateAssessment->reference($patientAge) : null,
            'oxygenSaturationReference' => $canViewVitals ? $oxygenSaturationAssessment->reference() : null,
            'temperatureReference' => $canViewVitals ? $temperatureAssessment->reference() : null,
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
                ->orderBy('name')
                ->get([
                    'uuid', 'code', 'name', 'unit',
                    'care_requires_allergy_check', 'care_recommends_vitals',
                ])
                ->map(fn (CatalogItem $item) => [
                    'uuid' => $item->uuid,
                    'code' => $item->code,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'care_requires_allergy_check' => $item->care_requires_allergy_check,
                    'care_recommends_vitals' => $item->care_recommends_vitals,
                ]),
            'careOrders' => $canViewCareOrders
                ? CareOrder::query()
                    ->where('care_orientation_id', $episodeOrientation->getKey())
                    ->with(['items.careRecordProcedures', 'requestedBy:id,name', 'consultation'])
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
                'can_complete' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress
                    && $request->user()->can('care.complete'),
                'can_view_care_orders' => $canViewCareOrders,
            ],
        ]);
    }

    public function saveRecord(
        UpdateCareRecordRequest $request,
        EpisodeOrientation $episodeOrientation,
        SaveCareRecordAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->validated(), $request->user());

        return redirect()->route('care.orientations.show', $episodeOrientation)
            ->with('status', 'Fiche de soins enregistrée.');
    }

    public function saveAndComplete(
        UpdateCareRecordRequest $request,
        EpisodeOrientation $episodeOrientation,
        SaveAndCompleteCareAction $action,
    ): RedirectResponse {
        $orientToMedicine = $request->boolean('orient_to_medicine');
        $action->execute(
            $episodeOrientation,
            $request->safe()->except('orient_to_medicine'),
            $request->user(),
            $orientToMedicine,
        );

        return redirect()->route('care.index')->with(
            'status',
            $orientToMedicine
                ? 'Actes enregistrés. Le patient est maintenant en attente en Médecine.'
                : 'Actes enregistrés et prise en charge terminée.',
        );
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

    public function accept(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        AcceptCareOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('care.orientations.show', $episodeOrientation)
            ->with('status', 'Patient pris en charge aux Soins.');
    }

    public function complete(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        CompleteCareAndOrientToMedicineAction $action,
    ): RedirectResponse {
        $completed = $action->execute($episodeOrientation, $request->user());

        $sentToMedicine = $completed->episode->orientations()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->exists();

        if ($sentToMedicine) {
            return back()->with('status', 'Soins terminés. Le patient est maintenant en attente en Médecine.');
        }

        return back()
            ->with('status', 'Soins terminés. Aucune consultation médicale n’est prévue pour ce parcours.')
            ->with('status_type', 'warning');
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
