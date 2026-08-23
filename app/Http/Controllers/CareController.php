<?php

namespace App\Http\Controllers;

use App\Actions\Care\AcceptCareOrientationAction;
use App\Actions\Care\CompleteCareAndOrientToMedicineAction;
use App\Actions\Care\SaveAndCompleteCareAction;
use App\Actions\Care\SaveCareRecordAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Http\Requests\UpdateCareRecordRequest;
use App\Models\AllergenReference;
use App\Models\CareRecord;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Support\BmiAssessment;
use App\Support\EpisodeQueuePresenter;
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
            ->whereHas('episode', fn ($query) => $query->where('status', 'OPEN'));

        $counts = [
            'active' => (clone $baseQuery)->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])->count(),
            'oriented' => (clone $baseQuery)
                ->where('status', EpisodeOrientationStatus::Completed->value)
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
            ->orderByRaw("CASE WHEN EXISTS (SELECT 1 FROM episodes WHERE episodes.id = episode_orientations.episode_id AND episodes.priority = 'EMERGENCY') THEN 0 ELSE 1 END")
            ->orderByDesc($filter === 'oriented' ? 'completed_at' : 'oriented_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (EpisodeOrientation $orientation) => $presenter->present($orientation));

        return Inertia::render('Care/Index', [
            'orientations' => $orientations,
            'counts' => $counts,
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

        return Inertia::render('Care/Show', [
            'orientation' => $presenter->present($episodeOrientation),
            'careRecord' => $this->recordPayload(
                $record,
                $canViewVitals,
                $canViewAllergies,
                $bmiAssessment,
                $patientAge,
            ),
            'bmiReference' => $canViewVitals ? $bmiAssessment->reference($patientAge) : null,
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
            'capabilities' => [
                'can_view_vitals' => $canViewVitals,
                'can_view_allergies' => $canViewAllergies,
                'can_manage_allergies' => $canManageAllergies,
                'can_edit' => $canEdit,
                'can_edit_vitals' => $canEditVitals,
                'can_complete' => $episodeOrientation->status === EpisodeOrientationStatus::InProgress
                    && $request->user()->can('care.complete'),
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

    /** @return array<string, mixed>|null */
    private function recordPayload(
        ?CareRecord $record,
        bool $canViewVitals,
        bool $canViewAllergies,
        BmiAssessment $bmiAssessment,
        ?int $patientAge,
    ): ?array {
        if (! $record) {
            return null;
        }

        return [
            'uuid' => $record->uuid,
            ...($canViewVitals ? [
                'blood_group' => $record->blood_group,
                'blood_pressure_left_systolic' => $record->blood_pressure_left_systolic,
                'blood_pressure_left_diastolic' => $record->blood_pressure_left_diastolic,
                'blood_pressure_right_systolic' => $record->blood_pressure_right_systolic,
                'blood_pressure_right_diastolic' => $record->blood_pressure_right_diastolic,
                'temperature_celsius' => $record->temperature_celsius,
                'known_diabetes' => $record->known_diabetes,
                'height_cm' => $record->height_cm,
                'weight_kg' => $record->weight_kg,
                'bmi' => $record->bmi,
                'bmi_assessment' => $bmiAssessment->classify($record->bmi, $patientAge),
                'smoker' => $record->smoker,
            ] : []),
            ...($canViewAllergies ? [
                'allergy_note' => $record->allergy_note,
                'allergy_snapshot' => $record->allergy_snapshot ?? [],
            ] : []),
            'no_procedure_reason' => $record->no_procedure_reason,
            'diagnostic_note' => $record->diagnostic_note,
            'transmission_reason' => $record->transmission_reason,
            'created_by' => $record->creator?->name,
            'updated_by' => $record->updater?->name,
            'updated_at' => $record->updated_at,
            'procedures' => $record->procedures->map(fn ($procedure) => [
                'uuid' => $procedure->uuid,
                'code' => $procedure->procedure_code,
                'name' => $procedure->procedure_name,
                'quantity' => $procedure->quantity,
                'notes' => $procedure->notes,
                'allergy_checked_at' => $procedure->allergy_checked_at,
                'performed_by' => $procedure->performer?->name,
                'performed_at' => $procedure->performed_at,
            ])->values(),
        ];
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
