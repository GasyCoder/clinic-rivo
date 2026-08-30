<?php

namespace App\Http\Controllers;

use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CancelDiagnosisAction;
use App\Actions\Medicine\CancelPrescriptionAction;
use App\Actions\Medicine\CorrectDiagnosisAction;
use App\Actions\Medicine\CreateCareOrderAction;
use App\Actions\Medicine\CreateImagingRequestAction;
use App\Actions\Medicine\CreateLabRequestAction;
use App\Actions\Medicine\CreatePrescriptionAction;
use App\Actions\Medicine\CreateServiceReferralAction;
use App\Actions\Medicine\CreateSurgicalReferralAction;
use App\Actions\Medicine\RecordDiagnosisAction;
use App\Actions\Medicine\RecordImagingResultAction;
use App\Actions\Medicine\RecordMedicalDischargeAction;
use App\Actions\Medicine\SaveConsultationAction;
use App\Actions\Medicine\UpdatePrescriptionAction;
use App\Enums\CatalogModule;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\PrescriptionStatus;
use App\Http\Requests\CancelMedicineDiagnosisRequest;
use App\Http\Requests\CancelMedicinePrescriptionRequest;
use App\Http\Requests\RecordImagingResultRequest;
use App\Http\Requests\StoreCareOrderRequest;
use App\Http\Requests\StoreImagingRequestRequest;
use App\Http\Requests\StoreLabRequestRequest;
use App\Http\Requests\StoreMedicalDischargeRequest;
use App\Http\Requests\StoreMedicineDiagnosisRequest;
use App\Http\Requests\StoreMedicinePrescriptionRequest;
use App\Http\Requests\StoreServiceReferralRequest;
use App\Http\Requests\StoreSurgicalReferralRequest;
use App\Http\Requests\UpdateMedicineConsultationRequest;
use App\Http\Requests\UpdateMedicineDiagnosisRequest;
use App\Http\Requests\UpdateMedicinePrescriptionRequest;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequestItem;
use App\Models\Prescription;
use App\Support\EpisodeQueuePresenter;
use App\Support\MedicineDossierPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MedicineController extends Controller
{
    public function index(Request $request, EpisodeQueuePresenter $presenter): Response
    {
        $filter = in_array($request->query('filter'), ['all', 'waiting', 'in_progress', 'emergency'], true)
            ? (string) $request->query('filter')
            : 'all';
        $search = trim((string) $request->query('q', ''));

        $baseQuery = EpisodeOrientation::query()
            ->where('destination_module', CatalogModule::Medicine->value)
            ->whereIn('status', [
                EpisodeOrientationStatus::Pending->value,
                EpisodeOrientationStatus::InProgress->value,
            ])
            ->whereHas('episode', fn ($query) => $query->where('status', 'OPEN'));

        $counts = [
            'all' => (clone $baseQuery)->count(),
            'waiting' => (clone $baseQuery)
                ->where('status', EpisodeOrientationStatus::Pending->value)
                ->count(),
            'in_progress' => (clone $baseQuery)
                ->where('status', EpisodeOrientationStatus::InProgress->value)
                ->count(),
            'emergency' => (clone $baseQuery)
                ->whereHas('episode', fn ($query) => $query->where('priority', 'EMERGENCY'))
                ->count(),
        ];

        $orientations = $baseQuery
            ->with([
                'episode.patient',
                'episode.billableItems',
                'episode.serviceRequests',
                'acceptedBy:id,name',
                'consultation:id,episode_orientation_id',
            ])
            ->when(
                $filter === 'waiting',
                fn ($query) => $query->where('status', EpisodeOrientationStatus::Pending->value),
            )
            ->when(
                $filter === 'in_progress',
                fn ($query) => $query->where('status', EpisodeOrientationStatus::InProgress->value),
            )
            ->when(
                $filter === 'emergency',
                fn ($query) => $query->whereHas('episode', fn ($episodeQuery) => $episodeQuery->where('priority', 'EMERGENCY')),
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
            // Only a still fast-tracked Emergency (Médecine hasn't yet
            // completed a first consultation for it) is pinned ahead of
            // arrival order — matches EpisodeQueuePresenter::isQueueEligible.
            // Once eligible, first arrived is always first in the list.
            ->orderByRaw(EpisodeQueuePresenter::PIN_UNSEEN_EMERGENCY_SQL)
            ->orderBy('oriented_at')
            ->paginate(20)
            ->withQueryString();

        $queueNumbers = $presenter->assignQueueNumbers($orientations->getCollection());
        $pendingReasons = $presenter->pendingReasonsFor($orientations->getCollection());
        $orientations->through(fn (EpisodeOrientation $orientation) => $presenter->present(
            $orientation,
            $queueNumbers[$orientation->getKey()] ?? null,
            $pendingReasons[$orientation->getKey()] ?? [],
        ));

        return Inertia::render('Medicine/Index', [
            'orientations' => $orientations,
            'counts' => $counts,
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    public function accept(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        AcceptMedicineOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'dossier'])
            ->with('status', 'Patient pris en charge en Médecine.');
    }

    public function begin(EpisodeOrientation $episodeOrientation): RedirectResponse
    {
        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'dossier']);
    }

    public function show(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        MedicineDossierPresenter $presenter,
    ): Response {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);
        abort_if($episodeOrientation->status === EpisodeOrientationStatus::Pending, 409, 'La consultation doit d’abord être prise en charge.');

        $step = (string) $request->route('step');

        $episodeOrientation->load([
            'episode.patient.allergies',
            'episode.patient.antecedents',
            'episode.billableItems',
            'episode.serviceRequests',
            'episode.careRecord.procedures.performer:id,name',
            'episode.medicalDischarge.creator:id,name',
            'consultation.doctor:id,name',
            'consultation.diagnoses.recordedBy:id,name',
            'consultation.diagnoses.cancellation.cancelledBy:id,name',
            'consultation.prescriptions.prescribedBy:id,name',
            'consultation.prescriptions.lines.medicine.catalogItem:id,uuid,unit',
            'acceptedBy:id,name',
            'completedBy:id,name',
        ]);

        abort_unless($episodeOrientation->consultation, 409, 'Le dossier de consultation doit être initialisé.');

        return Inertia::render('Medicine/Show', [
            ...$presenter->present(
                $episodeOrientation,
                $request->user(),
                includeMedicineCatalog: $step === 'ordonnance',
            ),
            'current_step' => $step,
        ]);
    }

    public function updateConsultation(
        UpdateMedicineConsultationRequest $request,
        EpisodeOrientation $episodeOrientation,
        SaveConsultationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->validated(), $request->user());

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'consultation'])
            ->with('status', 'Consultation enregistrée.');
    }

    public function storeDiagnosis(
        StoreMedicineDiagnosisRequest $request,
        EpisodeOrientation $episodeOrientation,
        RecordDiagnosisAction $action,
    ): RedirectResponse {
        $consultation = $episodeOrientation->consultation()->firstOrFail();
        $action->execute(
            $consultation,
            DiagnosisType::from($request->validated('type')),
            $request->validated('description'),
            $request->user(),
            $request->validated('diagnostic_catalog_uuid'),
            $request->validated('manual_code'),
            $request->validated('notes'),
        );

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'diagnostic'])
            ->with('status', 'Diagnostic ajouté au dossier médical.');
    }

    public function cancelDiagnosis(
        CancelMedicineDiagnosisRequest $request,
        EpisodeOrientation $episodeOrientation,
        CancelDiagnosisAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation,
            Diagnosis::query()->findOrFail($request->integer('diagnosis_id')),
            $request->user(),
        );

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'diagnostic'])
            ->with('status', 'Diagnostic annulé avec conservation de la trace médicale.')
            ->with('status_type', 'warning');
    }

    public function updateDiagnosis(
        UpdateMedicineDiagnosisRequest $request,
        EpisodeOrientation $episodeOrientation,
        CorrectDiagnosisAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation,
            Diagnosis::query()->findOrFail($request->integer('diagnosis_id')),
            DiagnosisType::from($request->validated('type')),
            $request->validated('description'),
            $request->user(),
        );

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'diagnostic'])
            ->with('status', 'Diagnostic rectifié avec conservation de la version précédente.');
    }

    public function storePrescription(
        StoreMedicinePrescriptionRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreatePrescriptionAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated('lines'),
            $request->user(),
        );

        $nextStep = $request->boolean('continue_to_decision') ? 'decision' : 'ordonnance';

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $nextStep])
            ->with('status', $nextStep === 'decision'
                ? 'Ordonnance enregistrée et stock réservé. Vous pouvez maintenant finaliser la décision médicale.'
                : 'Ordonnance enregistrée.');
    }

    public function cancelPrescription(
        CancelMedicinePrescriptionRequest $request,
        EpisodeOrientation $episodeOrientation,
        Prescription $prescription,
        CancelPrescriptionAction $action,
    ): RedirectResponse {
        $action->execute($prescription, $request->validated('reason'), $request->user());

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'ordonnance'])
            ->with('status', 'Ordonnance retirée. Le stock réservé a été libéré.')
            ->with('status_type', 'warning');
    }

    public function updatePrescription(
        UpdateMedicinePrescriptionRequest $request,
        EpisodeOrientation $episodeOrientation,
        Prescription $prescription,
        UpdatePrescriptionAction $action,
    ): RedirectResponse {
        $action->execute($prescription, $request->validated('lines'), $request->user());

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'ordonnance'])
            ->with('status', 'Ordonnance mise à jour. Les quantités réservées ont été recalculées.');
    }

    public function printPrescription(
        EpisodeOrientation $episodeOrientation,
        Prescription $prescription,
    ): Response {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);
        abort_unless(
            $prescription->consultation?->episode_orientation_id === $episodeOrientation->getKey(),
            404,
        );
        abort_unless($prescription->status === PrescriptionStatus::Active, 409, 'Seule une ordonnance active peut être imprimée.');

        $episodeOrientation->load(['episode.patient']);
        $prescription->load(['prescribedBy:id,name', 'lines' => fn ($query) => $query->orderBy('id')]);

        $episode = $episodeOrientation->episode;
        $patient = $episode->patient;

        return Inertia::render('Medicine/PrescriptionPrint', [
            'orientation' => ['uuid' => $episodeOrientation->uuid],
            'episode' => [
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
            ],
            'patient' => [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'sex' => $patient->sex->value,
                'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                'age' => $patient->birth_date?->age ?? $patient->declared_age,
            ],
            'prescription' => [
                'uuid' => $prescription->uuid,
                'prescribed_at' => $prescription->prescribed_at ?? $prescription->created_at,
                'prescribed_by' => $prescription->prescribedBy?->name,
                'lines' => $prescription->lines->map(fn ($line) => [
                    'id' => $line->getKey(),
                    'medication_name' => $line->medication_name,
                    'quantity' => $line->quantity,
                    'unit' => $line->medicine?->catalogItem?->unit,
                    'dosage' => $line->dosage,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'instructions' => $line->instructions,
                ])->values(),
            ],
        ]);
    }

    public function storeCareOrder(
        StoreCareOrderRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateCareOrderAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated('items'),
            $request->boolean('requires_return_to_medicine'),
            $request->input('instructions'),
            $request->user(),
        );

        return redirect()->route('medicine.index')
            ->with('status', 'Ordre de soins transmis. Le patient est orienté vers Soins.');
    }

    public function storeLabRequest(
        StoreLabRequestRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateLabRequestAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated('items'),
            $request->input('notes'),
            $request->user(),
        );

        $nextStep = $request->boolean('continue_to_diagnosis') ? 'diagnostic' : 'paraclinique';

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $nextStep])
            ->with('status', 'Demande d’analyses transmise au Laboratoire.');
    }

    public function storeImagingRequest(
        StoreImagingRequestRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateImagingRequestAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated('items'),
            $request->input('notes'),
            $request->user(),
        );

        $nextStep = $request->boolean('continue_to_diagnosis') ? 'diagnostic' : 'paraclinique';

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $nextStep])
            ->with('status', 'Demande d’imagerie enregistrée.');
    }

    public function recordImagingResult(
        RecordImagingResultRequest $request,
        EpisodeOrientation $episodeOrientation,
        ImagingRequestItem $imagingRequestItem,
        RecordImagingResultAction $action,
    ): RedirectResponse {
        $action->execute(
            $imagingRequestItem,
            $request->validated('result_value'),
            $request->input('result_notes'),
            $request->user(),
        );

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'paraclinique'])
            ->with('status', 'Compte rendu enregistré.');
    }

    public function storeSurgicalReferral(
        StoreSurgicalReferralRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateSurgicalReferralAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated('catalog_item_uuid'),
            $request->validated('diagnostic'),
            $request->input('indication'),
            $request->validated('priority'),
            $request->input('notes'),
            $request->user(),
        );

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'decision'])
            ->with('status', 'Demande de chirurgie transmise.');
    }

    public function storeReferral(
        StoreServiceReferralRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateServiceReferralAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            CatalogModule::from($request->validated('destination')),
            $request->validated('reason'),
            $request->user(),
        );

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'decision'])
            ->with('status', 'Demande transmise.');
    }

    public function discharge(
        StoreMedicalDischargeRequest $request,
        EpisodeOrientation $episodeOrientation,
        RecordMedicalDischargeAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->validated(), $request->user());

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'decision'])
            ->with('status', 'Sortie médicale enregistrée. Le dossier administratif reste ouvert pour la Réception / Caisse.');
    }
}
