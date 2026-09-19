<?php

namespace App\Http\Controllers;

use App\Actions\Medicine\AcceptMedicineOrientationAction;
use App\Actions\Medicine\CancelCareOrderItemAction;
use App\Actions\Medicine\CancelDiagnosisAction;
use App\Actions\Medicine\CancelParaclinicalRequestAction;
use App\Actions\Medicine\CancelPrescriptionAction;
use App\Actions\Medicine\CompleteConsultationAction;
use App\Actions\Medicine\CorrectCareRecordVitalsAction;
use App\Actions\Medicine\CorrectDiagnosisAction;
use App\Actions\Medicine\CorrectImagingResultAction;
use App\Actions\Medicine\CreateCareOrderAction;
use App\Actions\Medicine\CreateHospitalizationRequestAction;
use App\Actions\Medicine\CreateImagingRequestAction;
use App\Actions\Medicine\CreateLabRequestAction;
use App\Actions\Medicine\CreateMedicalReferralAction;
use App\Actions\Medicine\CreatePrescriptionAction;
use App\Actions\Medicine\CreateServiceReferralAction;
use App\Actions\Medicine\CreateSurgicalReferralAction;
use App\Actions\Medicine\DecideComplementaryExamsAction;
use App\Actions\Medicine\DecideDiagnosisTimingAction;
use App\Actions\Medicine\RecordConsultationOrientationAction;
use App\Actions\Medicine\RecordDiagnosisAction;
use App\Actions\Medicine\RecordImagingResultAction;
use App\Actions\Medicine\RecordMedicalDischargeAction;
use App\Actions\Medicine\ReleaseMedicineOrientationAction;
use App\Actions\Medicine\ReopenConsultationAction;
use App\Actions\Medicine\ResolveConsultationStepAction;
use App\Actions\Medicine\SaveConsultationAction;
use App\Actions\Medicine\UpdatePrescriptionAction;
use App\Enums\CatalogModule;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationType;
use App\Enums\ConsultationStep;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
use App\Enums\PrescriptionStatus;
use App\Http\Requests\CancelCareOrderItemRequest;
use App\Http\Requests\CancelMedicineDiagnosisRequest;
use App\Http\Requests\CancelMedicinePrescriptionRequest;
use App\Http\Requests\CorrectImagingResultRequest;
use App\Http\Requests\Medicine\CancelParaclinicalRequestRequest;
use App\Http\Requests\Medicine\CorrectCareRecordVitalsRequest;
use App\Http\Requests\Medicine\DecideComplementaryExamsRequest;
use App\Http\Requests\Medicine\DecideDiagnosisTimingRequest;
use App\Http\Requests\Medicine\ReopenConsultationRequest;
use App\Http\Requests\Medicine\ResolveConsultationStepRequest;
use App\Http\Requests\Medicine\SaveConsultationDraftRequest;
use App\Http\Requests\Medicine\SelectConsultationOrientationRequest;
use App\Http\Requests\Medicine\StoreHospitalizationRequestRequest;
use App\Http\Requests\Medicine\StoreMedicalReferralRequest;
use App\Http\Requests\Medicine\UpdateMedicineClinicalExamRequest;
use App\Http\Requests\Medicine\UpdateMedicineInterviewRequest;
use App\Http\Requests\PreviewImagingResultRequest;
use App\Http\Requests\RecordImagingResultRequest;
use App\Http\Requests\StoreCareOrderRequest;
use App\Http\Requests\StoreImagingRequestRequest;
use App\Http\Requests\StoreLabRequestRequest;
use App\Http\Requests\StoreMedicalDischargeRequest;
use App\Http\Requests\StoreMedicineDiagnosisRequest;
use App\Http\Requests\StoreMedicinePrescriptionRequest;
use App\Http\Requests\StoreServiceReferralRequest;
use App\Http\Requests\StoreSurgicalReferralRequest;
use App\Http\Requests\UpdateMedicineDiagnosisRequest;
use App\Http\Requests\UpdateMedicinePrescriptionRequest;
use App\Models\CareOrderItem;
use App\Models\ConsultationDraft;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Models\HospitalizationRequest;
use App\Models\ImagingRequestItem;
use App\Models\MedicalReferral;
use App\Models\Prescription;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Support\ConsultationWorkflow;
use App\Support\EpisodeQueuePresenter;
use App\Support\ImagingReportDocument;
use App\Support\MedicalReferralDocument;
use App\Support\MedicineDossierPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            ->whereHas('episode', fn ($query) => $query->where('status', 'OPEN'))
            // A patient is never truly deletable (ADR-010) — under normal
            // operation this can never fail to match. It only guards against
            // data corruption bypassing Eloquent entirely (e.g. a raw
            // TRUNCATE on patients), so an orphaned row disappears from the
            // queue instead of fataling the whole page.
            ->whereHas('episode.patient');

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

        // Sur toute la file, pas sur la page : le n° d'un patient ne change ni avec un filtre ni avec une
        // recherche, et il est le même que celui que les Soins affichent pour lui (ADR-124).
        $queueNumbers = $presenter->medicineQueueNumbers();
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

    /** ADR-127 : remettre en file, à sa place, un patient pris en charge par erreur. */
    public function release(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        ReleaseMedicineOrientationAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->user());

        return redirect()->route('medicine.index')
            ->with('status', 'Patient remis en file, à sa place.');
    }

    public function begin(EpisodeOrientation $episodeOrientation): RedirectResponse
    {
        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'dossier']);
    }

    public function show(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        MedicineDossierPresenter $presenter,
    ): Response|RedirectResponse {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);
        abort_if($episodeOrientation->status === EpisodeOrientationStatus::Pending, 409, 'La consultation doit d’abord être prise en charge.');

        $step = (string) $request->route('step');

        $episodeOrientation->load([
            'episode.patient.allergies',
            'episode.patient.antecedents',
            'episode.patient.treatments',
            'episode.billableItems',
            'episode.serviceRequests',
            'episode.careRecord.procedures.performer:id,name',
            'episode.medicalDischarge.creator:id,name',
            'consultation.doctor:id,name',
            'consultation.completedBy:id,name',
            'consultation.interviewedBy:id,name',
            'consultation.steps.completedBy:id,name',
            'consultation.clinicalExamination.findings',
            'consultation.clinicalExamination.examiner:id,name',
            'consultation.currentTreatments',
            'consultation.diagnoses.recordedBy:id,name',
            'consultation.diagnoses.cancellation.cancelledBy:id,name',
            'consultation.prescriptions.prescribedBy:id,name',
            'consultation.prescriptions.lines.medicine.catalogItem:id,uuid,unit',
            'acceptedBy:id,name',
            'completedBy:id,name',
        ]);

        // Le diagnostic se conclut dans l'examen clinique (ADR-081) : une URL
        // ancienne ou un signet y mène plutôt que vers un écran disparu.
        if ($step === 'diagnostic') {
            return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'examen']);
        }

        // La décision n'est plus une étape : elle se prend là où le médecin
        // en sait assez (ADR-084). L'ancienne URL mène à la Clôture, qui est
        // ce que cet écran faisait réellement en dernier.
        if ($step === 'decision') {
            return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'cloture']);
        }

        abort_unless($episodeOrientation->consultation, 409, 'Le dossier de consultation doit être initialisé.');
        abort_unless($episodeOrientation->episode->patient, 404, 'Le dossier patient de ce passage est introuvable.');

        return Inertia::render('Medicine/Show', [
            ...$presenter->present(
                $episodeOrientation,
                $request->user(),
                includeMedicineCatalog: $step === 'ordonnance',
            ),
            'current_step' => $step,
            // Typing in progress, restored after a reload. Scoped to this
            // account: a doctor never inherits another's unvalidated entry.
            'consultationDraft' => ConsultationDraft::query()
                ->where('episode_orientation_id', $episodeOrientation->getKey())
                ->where('created_by', $request->user()->getKey())
                ->first(['payload', 'updated_at'])
                ?->only(['payload', 'updated_at']),
        ]);
    }

    /**
     * Autosaved typing across the consultation wizard, so a reload never
     * loses it. Returns no Inertia response: the browser calls this in the
     * background and must not have its page re-rendered mid-typing.
     */
    public function saveDraft(
        SaveConsultationDraftRequest $request,
        EpisodeOrientation $episodeOrientation,
    ): JsonResponse {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);

        $draft = ConsultationDraft::query()->updateOrCreate(
            [
                'episode_orientation_id' => $episodeOrientation->getKey(),
                'created_by' => $request->user()->getKey(),
            ],
            ['payload' => $request->draftPayload()],
        );

        return response()->json(['saved_at' => $draft->updated_at->toIso8601String()]);
    }

    /** The doctor explicitly discards their entry. */
    public function discardDraft(
        Request $request,
        EpisodeOrientation $episodeOrientation,
    ): RedirectResponse {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);

        ConsultationDraft::query()
            ->where('episode_orientation_id', $episodeOrientation->getKey())
            ->where('created_by', $request->user()->getKey())
            ->delete();

        return back()->with('status', 'Saisie en cours annulée.');
    }

    public function updateInterview(
        UpdateMedicineInterviewRequest $request,
        EpisodeOrientation $episodeOrientation,
        SaveConsultationAction $action,
    ): RedirectResponse {
        // Normalised here so the action and the redirection below read the
        // same intent: a FormData submission sends "false" as a string, which
        // `=== false` would silently treat as "validate this step".
        $complete = $request->boolean('complete', true);
        $action->saveInterview(
            $episodeOrientation,
            [...$request->validated(), 'complete' => $complete],
            $request->user(),
        );

        // « Enregistrer » reste sur l'étape, « Enregistrer et continuer »
        // avance : la redirection suit l'intention, pas l'inverse.
        if (! $complete) {
            return back()->with('status', 'Interrogatoire enregistré.');
        }

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'examen'])
            ->with('status', 'Interrogatoire validé. Vous pouvez réaliser l’examen clinique.');
    }

    /**
     * Answers "are complementary exams needed?" at the head of the step it
     * governs. "Non" resolves the step as SKIPPED — declared unnecessary,
     * never forgotten — then continues the pathway.
     *
     * La suite est demandée à `ConsultationWorkflow::nextStepAfter()`, pas
     * codée en dur : le serveur reste la source de vérité de l'avancement
     * (ADR-076), et une étape devenue sans objet pour ce patient est ainsi
     * franchie sans que ce contrôleur ait à la connaître.
     */
    public function decideComplementaryExams(
        DecideComplementaryExamsRequest $request,
        EpisodeOrientation $episodeOrientation,
        DecideComplementaryExamsAction $decision,
        ResolveConsultationStepAction $steps,
        ConsultationWorkflow $workflow,
    ): RedirectResponse {
        $consultation = $episodeOrientation->consultation;
        $required = $request->boolean('required');
        $withdrawn = [];

        // Withdrawing is never silent: the browser confirms first, and a
        // request already carrying a result is refused outright.
        if (! $required && $decision->hasOutstandingRequests($consultation)) {
            if (! $request->boolean('withdraw_confirmed')) {
                throw ValidationException::withMessages([
                    'required' => 'Des examens complémentaires ont déjà été demandés. Confirmez l’annulation des demandes non réalisées avant de déclarer qu’aucun examen n’est nécessaire.',
                ]);
            }

            $withdrawn = $decision->withdrawOutstandingRequests($consultation, $request->user());
        }

        $decision->record($consultation, $required, $request->user());

        if (! $required) {
            $steps->skip(
                $consultation->fresh(),
                ConsultationStep::Paraclinical,
                'Aucun examen complémentaire nécessaire.',
                $request->user(),
            );

            // Le médecin vient de terminer l'Examen clinique : l'y renvoyer
            // le faisait repartir en arrière. On avance vers l'étape
            // suivante réellement pertinente — la Prescription en pratique,
            // la Clôture si elle ne concerne pas ce patient.
            $consultation = $consultation->fresh();
            $next = $workflow->nextStepAfter($consultation, ConsultationStep::Paraclinical)
                ?? ConsultationStep::Closure;

            return redirect()->route('medicine.orientations.step', [$episodeOrientation, $next->value])
                ->with('status', $withdrawn === []
                    ? 'Aucun examen complémentaire nécessaire.'
                    : sprintf('Aucun examen complémentaire nécessaire. %d demande(s) annulée(s).', count($withdrawn)));
        }

        return back()->with('status', 'Sélectionnez les examens complémentaires à demander.');
    }

    /**
     * Corriger une constante relevée par les Soins (ADR-093).
     *
     * Les constantes seules : la FormRequest interdit nommément les actes,
     * le matériel, les allergies et la transmission, et l'Action refuse de
     * son côté. L'écrasement est réel et tracé par `Auditable`.
     */
    public function correctCareVitals(
        CorrectCareRecordVitalsRequest $request,
        EpisodeOrientation $episodeOrientation,
        CorrectCareRecordVitalsAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $request->validated(), $request->user());

        return back()->with('status', 'Constantes corrigées. La valeur précédente reste tracée à l’audit.');
    }

    /**
     * The examination now also carries the decision that used to cost a step
     * of its own: does this patient need complementary exams? Answering "non"
     * resolves the Paraclinique step as SKIPPED and sends the doctor straight
     * to the Diagnostic, instead of making them open and leave an empty
     * screen.
     */
    public function updateClinicalExam(
        UpdateMedicineClinicalExamRequest $request,
        EpisodeOrientation $episodeOrientation,
        SaveConsultationAction $action,
    ): RedirectResponse {
        $complete = $request->boolean('complete', true);

        $action->saveClinicalExam(
            $episodeOrientation,
            [...$request->validated(), 'complete' => $complete],
            $request->user(),
        );

        if (! $complete) {
            return back()->with('status', 'Examen clinique enregistré.');
        }

        // ADR-095 — « Le diagnostic peut-il être posé maintenant ? » a rejoint
        // « Décision & clôture », avec son propre endpoint. L'examen ne décide
        // donc plus de la suite : il mène à la Paraclinique, l'étape suivante,
        // qui pose elle-même sa propre question (ADR-079). Le raccourci qui
        // sautait cette étape la contournait sans que personne y réponde.
        return redirect()->route('medicine.orientations.step', [$episodeOrientation, 'paraclinique'])
            ->with('status', 'Examen clinique validé.');
    }

    /**
     * The doctor resolves a step explicitly: validated, or declared
     * unnecessary for this patient. Never a side effect of opening a screen.
     */
    public function resolveStep(
        ResolveConsultationStepRequest $request,
        EpisodeOrientation $episodeOrientation,
        ResolveConsultationStepAction $action,
        ConsultationWorkflow $workflow,
    ): RedirectResponse {
        $consultation = $episodeOrientation->consultation;
        $step = ConsultationStep::from($request->validated('step'));

        if ($request->validated('intent') === 'SKIP') {
            $action->skip($consultation, $step, $request->validated('skip_reason'), $request->user());
            $status = sprintf('%s : étape déclarée non nécessaire.', $step->label());
        } else {
            $action->complete($consultation, $step, $request->user());
            $status = sprintf('%s validée.', $step->label());
        }

        // Resolving a step means moving on. Staying put would make the doctor
        // navigate by hand after every validation.
        $next = $workflow->nextStepAfter($consultation, $step);

        if ($next === null) {
            return back()->with('status', $status);
        }

        return redirect()
            ->route('medicine.orientations.step', [$episodeOrientation, $next->value])
            ->with('status', $status);
    }

    /** Closes the encounter once every relevant step is resolved. */
    public function completeConsultation(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        CompleteConsultationAction $action,
    ): RedirectResponse {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);
        abort_unless($episodeOrientation->consultation, 409);

        $action->execute($episodeOrientation->consultation, $request->user());

        return back()->with('status', 'Consultation clôturée.');
    }

    /**
     * "La conduite à tenir est-elle déjà déterminée ?" — answered from
     * wherever the doctor is, and answering it opens the matching request
     * form there and then (ADR-084). No type means "poursuivre l'évaluation".
     */
    public function selectOrientation(
        SelectConsultationOrientationRequest $request,
        EpisodeOrientation $episodeOrientation,
        RecordConsultationOrientationAction $action,
    ): RedirectResponse {
        $consultation = $episodeOrientation->consultation()->firstOrFail();
        $type = $request->validated('type');
        $priority = $request->validated('priority');

        if ($type === null) {
            $action->clear($consultation, $request->user());

            return back()->with('status', 'Orientation retirée : poursuite de l’évaluation.');
        }

        $orientation = $action->select(
            $consultation,
            ConsultationOrientationType::from($type),
            $priority ? ClinicalPriority::from($priority) : null,
            $request->user(),
        );

        return back()->with('status', sprintf(
            'Orientation « %s » enregistrée — complétez sa demande.',
            $orientation->type->label(),
        ));
    }

    public function storeHospitalizationRequest(
        StoreHospitalizationRequestRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateHospitalizationRequestAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated(),
            $request->user(),
        );

        return $this->backToStep($request, $episodeOrientation, 'Demande d’hospitalisation transmise.');
    }

    public function storeMedicalReferral(
        StoreMedicalReferralRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateMedicalReferralAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated(),
            $request->user(),
        );

        return $this->backToStep($request, $episodeOrientation, 'Référence / transfert transmis.');
    }

    /**
     * A transmitted request leaves the doctor exactly where they were — the
     * orientation can be settled at the interview, the examination or later,
     * and the wizard must not teleport them to a step they had not reached.
     */
    private function backToStep(
        Request $request,
        EpisodeOrientation $episodeOrientation,
        string $status,
    ): RedirectResponse {
        $step = ConsultationStep::tryFrom((string) $request->input('return_step'));

        if ($step === null || ! $step->isWizardStep()) {
            return back()->with('status', $status);
        }

        return redirect()
            ->route('medicine.orientations.step', [$episodeOrientation, $step->value])
            ->with('status', $status);
    }

    /**
     * ADR-095 — « Le diagnostic peut-il être posé maintenant ? », désormais
     * posée à « Décision & clôture ».
     *
     * Elle ne déplace personne : le médecin reste sur l'étape où il conclut.
     * « Pas maintenant » n'autorise aucune clôture ; elle explique seulement
     * pourquoi la consultation reste ouverte.
     */
    public function decideDiagnosisTiming(
        DecideDiagnosisTimingRequest $request,
        EpisodeOrientation $episodeOrientation,
        DecideDiagnosisTimingAction $decision,
    ): RedirectResponse {
        $ready = $request->boolean('ready');

        $decision->execute($episodeOrientation->consultation()->firstOrFail(), $ready, $request->user());

        return back()->with('status', $ready
            ? 'Diagnostic validé pour ce passage.'
            : 'Diagnostic différé : la consultation reste ouverte.');
    }

    /**
     * A diagnosis is recorded from the clinical examination or from
     * « Décision & clôture » (ADR-089): the doctor stays on the screen they
     * were on. Without a valid step, the examination remains the default.
     */
    private function diagnosisReturnStep(Request $request): string
    {
        $step = ConsultationStep::tryFrom((string) $request->input('return_step'));

        return $step !== null && $step->isWizardStep() ? $step->value : ConsultationStep::ClinicalExam->value;
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
            $request->validated('suggestion_protocol_uuid'),
            $request->validated('suggestion_source'),
        );

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $this->diagnosisReturnStep($request)])
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

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $this->diagnosisReturnStep($request)])
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

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $this->diagnosisReturnStep($request)])
            ->with('status', 'Diagnostic rectifié avec conservation de la version précédente.');
    }

    public function storePrescription(
        StoreMedicinePrescriptionRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreatePrescriptionAction $action,
        ResolveConsultationStepAction $steps,
        ConsultationWorkflow $workflow,
    ): RedirectResponse {
        $consultation = $episodeOrientation->consultation()->firstOrFail();

        $action->execute($consultation, $request->validated('lines'), $request->user());

        $continue = $request->boolean('continue_to_decision');

        // « Enregistrer et continuer » valide l'étape (ADR-076). Ce drapeau ne
        // faisait que rediriger : le médecin cliquait « Valider et réserver »,
        // atterrissait sur la Clôture, et y lisait « Prescription : à valider »
        // — le parcours l'avait emmené *au-delà* de l'étape qu'il devait
        // valider, sans jamais la valider.
        if ($continue) {
            $steps->complete($consultation->fresh(), ConsultationStep::Prescription, $request->user());
        }

        // `decision` n'est plus une destination : elle ne survit que comme
        // URL héritée, qui redirige vers la Clôture. Y envoyer un nouveau
        // parcours ferait payer une redirection pour arriver au même écran.
        // La suite est demandée au workflow, pas codée en dur.
        $next = $continue
            ? ($workflow->nextStepAfter($consultation->fresh(), ConsultationStep::Prescription)
                ?? ConsultationStep::Closure)
            : ConsultationStep::Prescription;

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $next->value])
            ->with('status', $continue
                ? 'Ordonnance enregistrée, stock réservé et étape validée. Vous pouvez maintenant conclure le passage.'
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

    /**
     * Rouvrir une consultation clôturée (ADR-096).
     *
     * Le médecin revient sur un dossier conclu — typiquement parce qu'un
     * résultat d'examen est arrivé après la clôture. L'action ne défait que
     * la clôture ; aucune donnée clinique n'est supprimée.
     */
    public function reopenConsultation(
        ReopenConsultationRequest $request,
        EpisodeOrientation $episodeOrientation,
        ReopenConsultationAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            $request->validated('reason'),
            $request->user(),
        );

        return redirect()
            ->route('medicine.orientations.step', [$episodeOrientation, 'cloture'])
            ->with('status', 'Consultation rouverte. Complétez le dossier, puis clôturez de nouveau.');
    }

    /**
     * Le compte rendu tel qu'il s'imprimerait, avant d'être enregistré
     * (ADR-108). Il ne crée rien : ni compte rendu, ni audit, ni changement
     * d'état de l'examen.
     */
    public function previewImagingResult(
        PreviewImagingResultRequest $request,
        EpisodeOrientation $episodeOrientation,
        ImagingRequestItem $imagingRequestItem,
        ClinicalRichTextSanitizer $richText,
    ): JsonResponse {
        $document = ImagingReportDocument::preview(
            $imagingRequestItem,
            $request->validated('result_value'),
            $request->input('result_notes'),
            $request->user(),
            $richText,
            // Feuille non touchée : le titre déjà enregistré, s'il y en a un.
            ($request->sheetChoice() ?? ['title' => $imagingRequestItem->report_sheet_title])['title'],
        );

        abort_if($document === null, 404);

        return response()->json(['document' => $document]);
    }

    /**
     * Le compte rendu d'imagerie, imprimable.
     *
     * Impression navigateur, jamais un PDF produit côté serveur : c'est la
     * limite déjà actée par l'ADR-070, et aucune dépendance serveur n'est
     * ajoutée ici. L'en-tête de clinique vient de `page.props.site`, donc
     * du site réellement déployé — Mampikony, Ambondromamy ou Boriziny
     * impriment chacun le leur sans code conditionnel.
     *
     * Rien n'est composé ici : la page réimprime exactement ce que le
     * médecin a enregistré. Un examen sans compte rendu n'a rien à
     * imprimer et renvoie 404 plutôt qu'une feuille vide portant l'en-tête
     * de la clinique.
     */
    public function printImagingReport(
        ImagingRequestItem $imagingRequestItem,
        ClinicalRichTextSanitizer $richText,
    ): Response {
        $document = ImagingReportDocument::for($imagingRequestItem, $richText);

        abort_if($document === null, 404);

        return Inertia::render('Medicine/ImagingReportPrint', ['document' => $document]);
    }

    /**
     * The admission slip and the referral letter.
     *
     * Both print exactly what the doctor already recorded on the request —
     * they add no field, ask no question and change no state. Printing is
     * therefore possible for a cancelled request too: a document that left
     * the clinic must stay reproducible.
     */
    public function printHospitalizationRequest(
        EpisodeOrientation $episodeOrientation,
        HospitalizationRequest $hospitalizationRequest,
    ): Response {
        $this->assertBelongsToConsultation($episodeOrientation, $hospitalizationRequest->consultation_id);
        $hospitalizationRequest->load('requestedBy:id,name');

        return Inertia::render('Medicine/HospitalizationRequestPrint', [
            ...$this->printHeader($episodeOrientation),
            'request' => [
                'uuid' => $hospitalizationRequest->uuid,
                'reason' => $hospitalizationRequest->reason,
                'admission_diagnosis' => $hospitalizationRequest->admission_diagnosis,
                'clinical_summary' => $hospitalizationRequest->clinical_summary,
                'planned_treatment' => $hospitalizationRequest->planned_treatment,
                'requested_service' => $hospitalizationRequest->requested_service,
                'requested_admission_at' => $hospitalizationRequest->requested_admission_at,
                'priority' => $hospitalizationRequest->priority->value,
                'priority_label' => $hospitalizationRequest->priority->label(),
                'instructions' => $hospitalizationRequest->instructions,
                'status' => $hospitalizationRequest->status->value,
                'status_label' => $hospitalizationRequest->status->label(),
                'requested_at' => $hospitalizationRequest->requested_at,
                'requested_by' => $hospitalizationRequest->requestedBy?->name,
            ],
        ]);
    }

    public function printMedicalReferral(
        EpisodeOrientation $episodeOrientation,
        MedicalReferral $medicalReferral,
    ): Response {
        $this->assertBelongsToConsultation($episodeOrientation, $medicalReferral->consultation_id);
        $medicalReferral->load('referredBy:id,name');

        return Inertia::render('Medicine/MedicalReferralPrint', [
            ...$this->printHeader($episodeOrientation),
            'referral' => [
                'uuid' => $medicalReferral->uuid,
                ...app(MedicalReferralDocument::class)->present($medicalReferral),
                'priority' => $medicalReferral->priority->value,
                'priority_label' => $medicalReferral->priority->label(),
                'status' => $medicalReferral->status->value,
                'status_label' => $medicalReferral->status->label(),
                'referred_at' => $medicalReferral->referred_at,
                'referred_by' => $medicalReferral->referredBy?->name,
            ],
        ]);
    }

    private function assertBelongsToConsultation(EpisodeOrientation $episodeOrientation, int $consultationId): void
    {
        abort_unless($episodeOrientation->destination_module === CatalogModule::Medicine, 404);
        abort_unless(
            $episodeOrientation->consultation()->whereKey($consultationId)->exists(),
            404,
        );
    }

    /** @return array<string, mixed> */
    private function printHeader(EpisodeOrientation $episodeOrientation): array
    {
        $episodeOrientation->load('episode.patient');
        $episode = $episodeOrientation->episode;
        $patient = $episode->patient;

        return [
            'orientation' => ['uuid' => $episodeOrientation->uuid],
            'episode' => [
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
            ],
            'patient' => [
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => $patient->birth_date_is_approximate,
                'age' => $patient->birth_date?->age ?? $patient->declared_age,
            ],
        ];
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

    /** Retirer un acte encore en attente aux Soins (voir CancelCareOrderItemAction). */
    public function cancelCareOrderItem(
        CancelCareOrderItemRequest $request,
        EpisodeOrientation $episodeOrientation,
        CareOrderItem $careOrderItem,
        CancelCareOrderItemAction $action,
    ): RedirectResponse {
        $action->execute($episodeOrientation, $careOrderItem, $request->validated('reason'), $request->user());

        return back()->with('status', 'Acte retiré de la demande de soins.');
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

        /*
         * Jamais un retour vers l'Examen clinique.
         *
         * Ce renvoi datait de l'ADR-080, quand le diagnostic se saisissait
         * dans l'examen : « continuer vers le diagnostic » voulait alors
         * dire « remonter à l'examen ». Depuis l'ADR-089 la conclusion vit à
         * l'étape « Décision & clôture », et demander une échographie ne
         * signifie évidemment pas qu'il faut recommencer l'examen physique.
         *
         * Le médecin reste donc sur la Paraclinique — d'où il peut en
         * demander une autre, en retirer une, ou saisir un résultat — ou
         * avance vers la Prescription s'il a demandé à poursuivre.
         */
        $nextStep = $request->boolean('continue_to_diagnosis') ? 'ordonnance' : 'paraclinique';

        return redirect()->route('medicine.orientations.step', [$episodeOrientation, $nextStep])
            ->with('status', 'Demande d’analyses transmise au Laboratoire.');
    }

    /**
     * Retirer une demande d'examen précise (ADR-079 pour la règle, appliquée
     * ici à une demande unique plutôt qu'à toutes).
     */
    public function cancelParaclinicalRequest(
        CancelParaclinicalRequestRequest $request,
        EpisodeOrientation $episodeOrientation,
        CancelParaclinicalRequestAction $action,
    ): RedirectResponse {
        $consultation = $episodeOrientation->consultation()->firstOrFail();
        $target = $request->target($consultation);

        $action->execute($consultation, $target, $request->input('reason'), $request->user());

        return back()->with('status', 'Demande retirée. Elle reste consultable dans le dossier.');
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

        /*
         * Jamais un retour vers l'Examen clinique.
         *
         * Ce renvoi datait de l'ADR-080, quand le diagnostic se saisissait
         * dans l'examen : « continuer vers le diagnostic » voulait alors
         * dire « remonter à l'examen ». Depuis l'ADR-089 la conclusion vit à
         * l'étape « Décision & clôture », et demander une échographie ne
         * signifie évidemment pas qu'il faut recommencer l'examen physique.
         *
         * Le médecin reste donc sur la Paraclinique — d'où il peut en
         * demander une autre, en retirer une, ou saisir un résultat — ou
         * avance vers la Prescription s'il a demandé à poursuivre.
         */
        $nextStep = $request->boolean('continue_to_diagnosis') ? 'ordonnance' : 'paraclinique';

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
            $request->sheetChoice(),
        );

        // Retour là d'où on vient. Un résultat se saisit aussi depuis
        // « Demandes d'examens », souvent après la clôture : y renvoyer le
        // médecin dans la consultation le déposerait sur un dossier en
        // lecture seule, sans rapport avec ce qu'il était en train de faire.
        return back()->with('status', 'Compte rendu enregistré.');
    }

    /** ADR-130 — corriger un compte rendu déjà enregistré ; l'ancienne version est conservée. */
    public function correctImagingResult(
        CorrectImagingResultRequest $request,
        EpisodeOrientation $episodeOrientation,
        ImagingRequestItem $imagingRequestItem,
        CorrectImagingResultAction $action,
    ): RedirectResponse {
        $action->execute(
            $imagingRequestItem,
            $request->validated('result_value'),
            $request->input('result_notes'),
            $request->input('reason'),
            $request->user(),
            $request->sheetChoice(),
        );

        return back()->with('status', 'Compte rendu corrigé. L’ancienne version est conservée.');
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

        return $this->backToStep($request, $episodeOrientation, 'Demande de chirurgie transmise.');
    }

    public function storeReferral(
        StoreServiceReferralRequest $request,
        EpisodeOrientation $episodeOrientation,
        CreateServiceReferralAction $action,
    ): RedirectResponse {
        $action->execute(
            $episodeOrientation->consultation()->firstOrFail(),
            CatalogModule::from($request->validated('destination')),
            trim((string) $request->validated('reason')) ?: null,
            $request->user(),
        );

        return $this->backToStep($request, $episodeOrientation, 'Demande transmise.');
    }

    public function discharge(
        StoreMedicalDischargeRequest $request,
        EpisodeOrientation $episodeOrientation,
        RecordMedicalDischargeAction $action,
    ): RedirectResponse {
        $discharge = $action->execute($episodeOrientation, $request->validated(), $request->user());

        // ADR-107 — un décès a une suite propre : l'acte de constatation.
        // Renvoyer le médecin à l'étape de clôture le laisserait devant un
        // dossier dont le travail restant n'est plus là. La consultation
        // reste ouverte et ré-ouvrable : seule la destination change, jamais
        // ce que la sortie a fait (ADR-084).
        if ($discharge->type === MedicalDischargeType::Deceased
            && $request->user()?->can('death_records.view')) {
            return redirect()->route('deaths.index')
                ->with('status', 'Décès prononcé. Établissez l’acte de constatation depuis ce registre.');
        }

        return $this->backToStep(
            $request,
            $episodeOrientation,
            'Sortie médicale enregistrée. Le dossier administratif reste ouvert pour la Réception / Caisse.',
        );
    }
}
