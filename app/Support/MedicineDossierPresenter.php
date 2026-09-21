<?php

namespace App\Support;

use App\Enums\AdministrationRoute;
use App\Enums\CareOrderStatus;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ClinicalExamSystem;
use App\Enums\ClinicalPriority;
use App\Enums\ClinicalSystemStatus;
use App\Enums\ConsultationDecision;
use App\Enums\ConsultationOrientationType;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationStep;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalDischargeType;
use App\Enums\PatientAntecedentType;
use App\Enums\PrescriptionStatus;
use App\Models\CareOrder;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\ConsultationOrientation;
use App\Models\EpisodeOrientation;
use App\Models\EpisodeServiceRequest;
use App\Models\HospitalStay;
use App\Models\ImagingRequest;
use App\Models\ImagingRequestItem;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Billing\PlannedServiceBilling;
use App\Services\Care\CareRecordReadModel;
use App\Services\Medicine\ClinicalProtocolMatcher;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Services\Medicine\ClinicPracticeAdvisor;
use App\Services\Medicine\ClinicPracticeIndex;
use App\Services\Medicine\ImagingReportTemplateCatalog;
use App\Services\Pharmacy\MedicineStockService;
use App\Support\Medicine\PrescriptionSuggestions;
use Illuminate\Support\Collection;

class MedicineDossierPresenter
{
    public function __construct(
        private readonly EpisodeQueuePresenter $queuePresenter,
        private readonly MedicineStockService $medicineStock,
        private readonly CareRecordReadModel $careRecord,
        private readonly ClinicalRichTextSanitizer $richText,
        private readonly ConsultationWorkflow $workflow,
        private readonly PlannedServiceBilling $plannedBilling,
        private readonly ClinicalProtocolMatcher $protocols,
        private readonly ClinicPracticeAdvisor $practice,
        private readonly PrescriptionSuggestions $prescriptionSuggestions,
    ) {}

    /** @return array<string, mixed> */
    public function present(
        EpisodeOrientation $orientation,
        User $user,
        bool $includeMedicineCatalog = false,
    ): array {
        $episode = $orientation->episode;
        // ADR-149 — le patient est-il dans un lit ? Cela change la conduite à
        // tenir qu'on peut lui proposer, et l'écran doit le dire en tête.
        $stay = HospitalStay::query()
            ->where('episode_id', $episode->getKey())
            ->where('status', HospitalStayStatus::Active->value)
            ->first();
        $patient = $episode->patient;
        $consultation = $orientation->consultation;
        $clinicalExamination = $consultation?->clinicalExamination;
        $careRecord = $episode->careRecord;
        $canViewMedicalRecord = $user->can('medical_record.view');
        $canViewDiagnoses = $user->can('diagnoses.view');
        $canViewPrescriptions = $user->can('prescriptions.view');
        $canViewPharmacyAvailability = $user->can('medicines.view')
            && $user->can('stock.availability.view');
        $canViewCareOrders = $user->can('care_orders.view');
        $canViewLabRequests = $user->can('laboratory_orders.view');
        $canViewImagingRequests = $user->can('imaging_orders.view');
        // Une seule instance pour tout l'écran : elle garde en mémoire les
        // réglages de feuille et n'interroge la base qu'une fois (ADR-108).
        $templateCatalog = app(ImagingReportTemplateCatalog::class);
        // Une consultation clôturée est en lecture seule partout — mais la
        // clôture est un statut, pas la présence d'une sortie médicale.
        //
        // La clause `medicalDischarge === null` qui figurait ici est
        // exactement le défaut que l'ADR-084 a supprimé : « la consultation
        // devenait lecture seule à l'instant où le médecin remplissait ce
        // formulaire — impossible de prescrire, d'imprimer ou de relire
        // ensuite ». Depuis l'ADR-084, `RecordMedicalDischargeAction`
        // enregistre la sortie sans rien terminer, et
        // `CompleteConsultationAction` est le seul acte qui termine la
        // rencontre. Le presenter avait conservé l'ancienne règle et
        // reverrouillait donc tout le dossier dès la sortie enregistrée,
        // avant même que la moindre étape soit validée.
        $isActive = $orientation->status === EpisodeOrientationStatus::InProgress
            && ($consultation === null || $consultation->isEditable());
        // L'orientation Soins du même passage : c'est elle qui porte la
        // fiche, et son UUID est ce qui permet d'y renvoyer le médecin.
        $careOrientation = $episode->orientations()
            ->where('destination_module', CatalogModule::Care->value)
            ->latest('id')
            ->first();

        $referralDestinations = [
            CatalogModule::Maternity->value => CatalogModule::Maternity->label(),
            CatalogModule::Hospitalization->value => CatalogModule::Hospitalization->label(),
            CatalogModule::Transfer->value => CatalogModule::Transfer->label(),
            CatalogModule::Pediatrics->value => CatalogModule::Pediatrics->label(),
        ];
        $transferDestinations = collect(ClinicSites::others());

        // Whether "Continuer la prise en charge" is a genuine option rather
        // than an escape hatch from a real decision (item 12): only true
        // when something clinically justifies waiting.
        $pendingLabCount = $consultation
            ? LabRequest::query()->where('consultation_id', $consultation->getKey())->with('items')
                ->get()->filter(fn (LabRequest $r) => $r->displayStatus() !== 'COMPLETED')->count()
            : 0;
        $pendingImagingCount = $consultation
            ? ImagingRequest::query()->where('consultation_id', $consultation->getKey())->with('items')
                ->get()->filter(fn (ImagingRequest $r) => $r->displayStatus() !== 'COMPLETED')->count()
            : 0;
        $pendingCareOrderCount = $consultation
            ? CareOrder::query()->where('consultation_id', $consultation->getKey())->with('items.careRecordProcedures')
                ->get()->filter(fn (CareOrder $o) => $o->displayStatus() !== 'COMPLETED')->count()
            : 0;
        $pendingReasons = array_values(array_filter([
            $pendingLabCount > 0
                ? ($pendingLabCount > 1 ? "{$pendingLabCount} analyses en attente de résultat" : '1 analyse en attente de résultat')
                : null,
            $pendingImagingCount > 0
                ? ($pendingImagingCount > 1 ? "{$pendingImagingCount} examens d’imagerie en attente de compte rendu" : '1 examen d’imagerie en attente de compte rendu')
                : null,
            $pendingCareOrderCount > 0
                ? ($pendingCareOrderCount > 1 ? "{$pendingCareOrderCount} ordres de soins en cours" : '1 ordre de soins en cours')
                : null,
        ]));

        $base = $this->queuePresenter->present($orientation);
        $base['episode']['medical_status'] = $episode->medical_status?->value;
        $base['episode']['medical_status_label'] = $episode->medical_status?->label();
        $base['episode']['emergency_contact'] = [
            'name' => $episode->emergency_contact_name,
            'phone' => $episode->emergency_contact_phone,
            'relationship' => $episode->emergency_contact_relationship,
            'email' => $episode->emergency_contact_email,
        ];
        $base['episode']['patient'] = [
            ...$base['episode']['patient'],
            // L'en-tête clinique affiche la date de naissance à côté de
            // l'âge : deux patients du même âge n'ont pas le même dossier,
            // et la date est ce qui lève l'ambiguïté à l'appel.
            'birth_date' => $patient->birth_date?->toDateString(),
            'phone' => $patient->phone,
            'email' => $patient->email,
            'address' => $patient->address,
            'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
        ];

        return [
            'orientation' => $base,
            // Same permission-aware projection Care and Surgery/Anesthesia
            // read from (ADR-048): a single place computes the constants'
            // threshold assessments, so Médecine never re-derives them.
            'care_record' => $careRecord === null ? null : [
                ...$this->careRecord->present($careRecord, $user),
                /*
                 * La fiche Soins complète — constantes, actes réalisés,
                 * observations — s'ouvre sur son propre écran plutôt que
                 * d'être recopiée ici. Une seule fiche, un seul formulaire,
                 * un seul jeu de règles : en rebâtir une seconde version
                 * dans la consultation, c'était garantir que les deux
                 * divergent.
                 *
                 * Le lien n'apparaît que si le passage est réellement passé
                 * par les Soins et que le médecin peut y écrire.
                 */
                'full_record_url' => $careOrientation !== null && $user->can('care.update')
                    ? "/care/orientations/{$careOrientation->uuid}"
                    : null,
            ],
            // The civil identity the doctor reads before examining. Served
            // here rather than in the queue projection, which stays lean:
            // a waiting list needs a name and an age, a file needs the rest.
            'patient_profile' => [
                'full_name' => trim("{$patient->last_name} {$patient->first_name}"),
                'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
                'birth_date' => $patient->birth_date?->toDateString(),
                'birth_date_is_approximate' => (bool) $patient->birth_date_is_approximate,
                'declared_age' => $patient->declared_age,
                'age' => $patient->birth_date?->age ?? $patient->declared_age,
                'birth_place' => $patient->birth_place,
                'marital_status' => $patient->marital_status?->label(),
                'children_count' => $patient->children_count,
                'profession' => $patient->profession,
                'identity_document' => $patient->identity_document_number
                    ? trim(($patient->identity_document_type?->value ?? '').' '.$patient->identity_document_number)
                    : null,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'address' => $patient->address,
                'patient_number' => $patient->patient_number,
            ],
            'allergies' => $user->can('patients.medical_history.view')
                ? $patient->allergies->map(fn ($allergy) => [
                    'uuid' => $allergy->uuid,
                    'substance' => $allergy->substance,
                    'reaction' => $allergy->reaction,
                    'severity' => $allergy->severity?->value,
                ])->values()
                : [],
            // The DOSSIER MÉDICAL reads the patient's own history and the
            // family's separately, so they are served separately. `antecedents`
            // stays as the full list for any caller that already used it.
            'antecedents' => $user->can('patients.medical_history.view')
                ? $patient->antecedents->map(fn ($antecedent) => [
                    'uuid' => $antecedent->uuid,
                    'type' => $antecedent->type->value,
                    'type_label' => $antecedent->type->shortLabel(),
                    'description' => $antecedent->description,
                ])->values()
                : [],
            'familial_antecedents' => $user->can('patients.medical_history.view')
                ? $patient->antecedents
                    ->where('type', PatientAntecedentType::Familial)
                    ->map(fn ($antecedent) => [
                        'uuid' => $antecedent->uuid,
                        'description' => $antecedent->description,
                    ])->values()
                : [],
            // The patient's known habitual treatments. Shown so the doctor
            // reads them instead of re-typing them: the interview asks only
            // whether the patient reports a change (ADR-078). Never a
            // prescription, never a Pharmacy product.
            'habitual_treatments' => $user->can('patients.medical_history.view')
                ? $patient->treatments
                    ->where('active', true)
                    ->map(fn ($treatment) => [
                        'uuid' => $treatment->uuid,
                        'medication_name' => $treatment->medication_name,
                        'dosage' => $treatment->dosage,
                        'frequency' => $treatment->frequency,
                        'duration' => $treatment->duration,
                        'notes' => $treatment->notes,
                    ])->values()
                : [],
            'consultation' => $consultation ? [
                'id' => $consultation->getKey(),
                'doctor' => $consultation->doctor?->name,
                // The interview, semi-structured: a short exploitable chief
                // complaint beside the narrative, instead of both melted into
                // one block of prose.
                'chief_complaint' => $consultation->chief_complaint,
                'symptom_onset' => $consultation->symptom_onset,
                'evolution' => $consultation->evolution?->value,
                'evolution_label' => $consultation->evolution?->label(),
                'additional_notes' => $consultation->additional_notes,
                'known_treatment_change' => $consultation->known_treatment_change,
                'known_treatment_change_notes' => $consultation->known_treatment_change_notes,
                // Snapshots of what the patient reported at this encounter.
                // They stay on the consultation even if the permanent record
                // is later corrected — the history is not rewritten.
                'reported_allergies' => $consultation->reported_allergies ?? [],
                'reported_antecedents' => $consultation->reported_antecedents ?? [],
                'reported_habitual_treatments' => $consultation->reported_habitual_treatments ?? [],
                'interviewed_by' => $consultation->interviewedBy?->name,
                'interviewed_at' => $consultation->interviewed_at,
                'status' => $consultation->status->value,
                'status_label' => $consultation->status->label(),
                'is_editable' => $consultation->isEditable(),
                'completed_at' => $consultation->completed_at,
                'completed_by' => $consultation->completedBy?->name,
                // The stepper renders these and nothing else: a step is never
                // shown as finished because the doctor merely opened it.
                'steps' => $this->workflow->steps($consultation)
                    ->mapWithKeys(fn (array $entry): array => [
                        $entry['step']->value => [
                            'status' => $entry['status']->value,
                            'status_label' => $entry['status']->label(),
                            'relevant' => $entry['relevant'],
                            'skippable' => $entry['skippable'],
                            'resolved' => $entry['status']->isResolved(),
                            'completed_at' => $entry['completed_at'],
                            'completed_by' => $entry['completed_by'],
                            'skip_reason' => $entry['skip_reason'],
                            'blocker' => $this->workflow->blockerFor($consultation, $entry['step']),
                            'note' => match ($entry['step']) {
                                ConsultationStep::Paraclinical => $this->workflow->paraclinicalNote($consultation),
                                // ADR-095 — la note suit la question : elle
                                // visait l'étape Diagnostic, retirée de
                                // l'assistant par l'ADR-081, et ne s'affichait
                                // donc plus nulle part.
                                ConsultationStep::Closure => $this->workflow->diagnosisNote($consultation),
                                default => null,
                            },
                        ],
                    ]),
                'closure_blockers' => $this->workflow->blockersForClosure($consultation),
                // ADR-109 — le besoin de l'arrivée, là où il se transforme en
                // demande. Le patient est venu pour une échographie : la
                // Paraclinique doit la proposer, pas la faire rechercher.
                'planned_paraclinical' => $this->plannedParaclinical($consultation),
                // ADR-094 — l'écran doit dire la même chose que le serveur :
                // sans ce drapeau, le formulaire de sortie afficherait encore
                // « Diagnostic final * » et son bandeau d'avertissement sur
                // un passage où plus rien ne l'exige.
                'requires_final_diagnosis' => $this->workflow->requiresFinalDiagnosis($consultation),
                'reason' => $this->richText->toSafeHtml($consultation->reason),
                'clinical_exam' => $this->richText->toSafeHtml($consultation->clinical_exam),
                // The structured examination. Systems the doctor never looked
                // at come back as NOT_EXAMINED — the grid is always complete
                // so the screen can state what was not examined instead of
                // leaving a gap the reader has to interpret.
                'clinical_examination' => [
                    'general_condition' => $clinicalExamination?->general_condition?->value,
                    'general_condition_label' => $clinicalExamination?->general_condition?->label(),
                    'consciousness_status' => $clinicalExamination?->consciousness_status?->value,
                    'consciousness_status_label' => $clinicalExamination?->consciousness_status?->label(),
                    'consciousness_details' => $clinicalExamination?->consciousness_details,
                    'general_observation' => $clinicalExamination?->general_observation,
                    // Tri-state: null is "not decided", never "non".
                    'complementary_exams_required' => $clinicalExamination?->complementary_exams_required,
                    'diagnosis_ready' => $clinicalExamination?->diagnosis_ready,
                    // Whether answering "non" would withdraw something.
                    'has_outstanding_requests' => $consultation !== null
                        && ($consultation->labRequests()->whereNull('cancelled_at')->exists()
                            || $consultation->imagingRequests()->whereNull('cancelled_at')->exists()),
                    'examined_by' => $clinicalExamination?->examiner?->name,
                    'examined_at' => $clinicalExamination?->examined_at,
                    'systems' => ($clinicalExamination
                        ? $clinicalExamination->systems()
                        : $this->emptyClinicalSystems())
                        ->map(fn (array $entry): array => [
                            'system_code' => $entry['system']->value,
                            'label' => $entry['system']->label(),
                            'hint' => $entry['system']->findingsHint(),
                            'status' => $entry['status']->value,
                            'status_label' => $entry['status']->label(),
                            'findings' => $entry['findings'],
                        ])->values(),
                ],
                'current_treatments' => $consultation->currentTreatments
                    ->map(fn ($treatment) => [
                        'uuid' => $treatment->uuid,
                        'medication_name' => $treatment->medication_name,
                        'dosage' => $treatment->dosage,
                        'frequency' => $treatment->frequency,
                        'duration' => $treatment->duration,
                        'notes' => $treatment->notes,
                    ])->values(),
                'decision' => $consultation->decision?->value,
                'decision_label' => $consultation->decision?->label(),
                'decision_notes' => $consultation->decision_notes,
                'consulted_at' => $consultation->consulted_at,
                'diagnoses' => $canViewDiagnoses
                    ? $consultation->diagnoses->map(fn ($diagnosis) => [
                        'id' => $diagnosis->getKey(),
                        'type' => $diagnosis->type->value,
                        'type_label' => $diagnosis->type === DiagnosisType::Final
                            ? 'Diagnostic final'
                            : 'Hypothèse',
                        'description' => $diagnosis->description,
                        'source' => $diagnosis->is_manual ? 'MANUAL' : 'CATALOG',
                        'source_label' => $diagnosis->is_manual ? 'Manuel' : 'Catalogue',
                        'code' => $diagnosis->is_manual
                            ? $diagnosis->manual_code
                            : $diagnosis->catalog_code_snapshot,
                        'notes' => $diagnosis->notes,
                        'recorded_by' => $diagnosis->recordedBy?->name,
                        'recorded_at' => $diagnosis->created_at,
                        'cancelled' => $diagnosis->cancellation !== null,
                        'cancel_reason' => $diagnosis->cancellation?->reason,
                        'cancelled_by' => $diagnosis->cancellation?->cancelledBy?->name,
                        'cancelled_at' => $diagnosis->cancellation?->cancelled_at,
                        'correction' => $diagnosis->cancellation?->replacement_diagnosis_id !== null,
                        'can_cancel' => $isActive
                            && $user->can('diagnoses.update')
                            && $diagnosis->recorded_by === $user->getKey()
                            && $diagnosis->cancellation === null,
                        'can_edit' => $isActive
                            && $user->can('diagnoses.update')
                            && $diagnosis->recorded_by === $user->getKey()
                            && $diagnosis->cancellation === null,
                    ])->values()
                    : [],
                'prescriptions' => $canViewPrescriptions
                    ? $consultation->prescriptions
                        ->where('status', PrescriptionStatus::Active)
                        ->map(fn ($prescription) => [
                            'uuid' => $prescription->uuid,
                            'prescribed_at' => $prescription->prescribed_at ?? $prescription->created_at,
                            'can_edit' => $isActive && $user->can('prescriptions.update'),
                            'can_remove' => $isActive && $user->can('prescriptions.cancel'),
                            'lines' => $prescription->lines->map(fn ($line) => [
                                'id' => $line->getKey(),
                                'medicine_uuid' => $line->medicine?->catalogItem?->uuid,
                                'medication_name' => $line->medication_name,
                                'route' => $line->route?->value,
                                'route_label' => $line->route?->label(),
                                'route_short' => $line->route?->shortLabel(),
                                // Composée côté serveur : l'écran n'a jamais à
                                // deviner l'unité d'un nombre nu.
                                'posology' => collect([
                                    $line->dosage,
                                    $line->route?->shortLabel(),
                                    $line->frequency,
                                    $line->duration,
                                ])->filter()->implode(' · '),
                                'quantity' => $line->quantity,
                                'unit' => $line->medicine?->catalogItem?->unit,
                                'stock_available_at_prescription' => $line->stock_available_at_prescription,
                                'earliest_expiration_at' => $line->earliest_expiration_at?->toDateString(),
                                'dosage' => $line->dosage,
                                'frequency' => $line->frequency,
                                'duration' => $line->duration,
                                'instructions' => $line->instructions,
                                'is_manual_entry' => $line->is_manual_entry,
                                'catalog_review_status' => $line->catalog_review_status?->value,
                                'catalog_reviewed_at' => $line->catalog_reviewed_at,
                            ])->values(),
                        ])->values()
                    : [],
                'care_orders' => $canViewCareOrders
                    ? CareOrder::query()
                        ->where('consultation_id', $consultation->getKey())
                        ->with(['items.careRecordProcedures', 'items.catalogItem:id,uuid', 'items.cancelledBy:id,name', 'careOrientation', 'requestedBy:id,name'])
                        ->latest('ordered_at')
                        ->get()
                        ->map(fn (CareOrder $careOrder) => [
                            'uuid' => $careOrder->uuid,
                            'requested_by' => $careOrder->requestedBy?->name,
                            'instructions' => $careOrder->instructions,
                            'requires_return_to_medicine' => $careOrder->requires_return_to_medicine,
                            'status' => $careOrder->displayStatus(),
                            'ordered_at' => $careOrder->ordered_at,
                            'completed_at' => $careOrder->completed_at,
                            'items' => $careOrder->items->map(fn ($item) => [
                                'uuid' => $item->uuid,
                                // The act itself, not this request line: the
                                // same key CreateCareOrderAction uses to refuse
                                // a second pending request for the same act.
                                'catalog_item_uuid' => $item->catalogItem?->uuid,
                                'name' => $item->catalog_item_name_snapshot,
                                'code' => $item->catalog_item_code_snapshot,
                                'quantity' => $item->quantity,
                                'realized_quantity' => $item->realizedQuantity(),
                                'remaining_quantity' => $item->remainingQuantity(),
                                'not_performed_at' => $item->not_performed_at,
                                'not_performed_reason' => $item->not_performed_reason,
                                'cancelled_at' => $item->cancelled_at,
                                'cancelled_by' => $item->cancelledBy?->name,
                                'cancel_reason' => $item->cancel_reason,
                                // Mirrors CancelCareOrderItemAction, which re-checks:
                                // withdrawable while the order is under way and the
                                // act has not been performed.
                                'can_cancel' => $isActive
                                    && $user->can('care_orders.create')
                                    && ! $item->isCancelled()
                                    && (float) $item->realizedQuantity() === 0.0
                                    && $careOrder->status === CareOrderStatus::Pending,
                                'instructions' => $item->instructions,
                            ])->values(),
                        ])->values()
                    : [],
                'lab_requests' => $canViewLabRequests
                    ? LabRequest::query()
                        ->where('consultation_id', $consultation->getKey())
                        // Une demande retirée quitte le plan de
                        // soins ; elle reste en base, auditée.
                        ->whereNull('cancelled_at')
                        ->with(['items.resultedBy:id,name', 'items.catalogItem:id,uuid', 'requestedBy:id,name'])
                        ->latest('requested_at')
                        ->get()
                        ->map(fn (LabRequest $labRequest) => [
                            'uuid' => $labRequest->uuid,
                            'requested_by' => $labRequest->requestedBy?->name,
                            'notes' => $labRequest->notes,
                            'status' => $labRequest->displayStatus(),
                            'requested_at' => $labRequest->requested_at,
                            'items' => $labRequest->items->map(fn ($item) => [
                                'uuid' => $item->uuid,
                                // L'UUID de la prestation, et non seulement son
                                // libellé figé : c'est lui que le sélecteur
                                // compare pour ne pas proposer un examen déjà
                                // demandé — un libellé se compare mal et un
                                // instantané peut différer du catalogue actuel.
                                'catalog_item_uuid' => $item->catalogItem?->uuid,
                                'name' => $item->catalog_item_name_snapshot,
                                'code' => $item->catalog_item_code_snapshot,
                                'result_value' => $item->result_value,
                                'result_notes' => $item->result_notes,
                                'resulted_at' => $item->resulted_at,
                                'resulted_by' => $item->resultedBy?->name,
                            ])->values(),
                        ])->values()
                    : [],
                'imaging_requests' => $canViewImagingRequests
                    ? ImagingRequest::query()
                        ->where('consultation_id', $consultation->getKey())
                        // Une demande retirée quitte le plan de
                        // soins ; elle reste en base, auditée.
                        ->whereNull('cancelled_at')
                        ->with(['items.resultedBy:id,name', 'items.catalogItem:id,uuid', 'requestedBy:id,name'])
                        ->latest('requested_at')
                        ->get()
                        ->map(fn (ImagingRequest $imagingRequest) => [
                            'uuid' => $imagingRequest->uuid,
                            'requested_by' => $imagingRequest->requestedBy?->name,
                            'notes' => $imagingRequest->notes,
                            'status' => $imagingRequest->displayStatus(),
                            'requested_at' => $imagingRequest->requested_at,
                            'items' => $imagingRequest->items->map(fn ($item) => [
                                'uuid' => $item->uuid,
                                'catalog_item_uuid' => $item->catalogItem?->uuid,
                                'name' => $item->catalog_item_name_snapshot,
                                'code' => $item->catalog_item_code_snapshot,
                                // ADR-108 — la feuille à pré-appliquer à l'ouverture de la saisie.
                                'default_template_key' => $templateCatalog->defaultKeyFor($item),
                                'result_value' => $item->result_value,
                                'result_notes' => $item->result_notes,
                                'resulted_at' => $item->resulted_at,
                                'resulted_by' => $item->resultedBy?->name,
                            ])->values(),
                        ])->values()
                    : [],
                'surgical_requests' => $user->can('surgery.request')
                    ? $episode->surgicalRequests()
                        ->latest('created_at')
                        ->get()
                        ->map(fn ($request) => [
                            'uuid' => $request->uuid,
                            'procedure_name' => $request->procedure_name,
                            'procedure_details' => $request->procedure_details,
                            'notes' => $request->notes,
                            'status' => $request->status->value,
                        ])->values()
                    : [],
                'referrals' => $canViewMedicalRecord
                    ? $episode->orientations()
                        ->whereIn('destination_module', array_keys($referralDestinations))
                        ->latest('oriented_at')
                        ->get()
                        ->map(fn ($referral) => [
                            'uuid' => $referral->uuid,
                            'destination' => $referral->destination_module->value,
                            'destination_label' => $referral->destination_module->label(),
                            'reason' => $referral->reason,
                            'status' => $referral->status->value,
                            'status_label' => $referral->status->label(),
                            'oriented_at' => $referral->oriented_at,
                        ])->values()
                    : [],
            ] : null,
            'previous_consultations' => $canViewMedicalRecord
                ? $episode->consultations()
                    ->where('id', '!=', $consultation?->getKey())
                    ->with('doctor:id,name')
                    ->latest('consulted_at')
                    ->get()
                    ->map(fn ($previous) => [
                        'id' => $previous->getKey(),
                        'doctor' => $previous->doctor?->name,
                        'reason' => $this->richText->toSafeHtml($previous->reason),
                        'decision_label' => $previous->decision?->label(),
                        'consulted_at' => $previous->consulted_at,
                    ])->values()
                : [],
            'medical_discharge' => $canViewMedicalRecord && $episode->medicalDischarge ? [
                'uuid' => $episode->medicalDischarge->uuid,
                'type' => $episode->medicalDischarge->type->value,
                'type_label' => $episode->medicalDischarge->type->label(),
                'final_diagnosis' => $episode->medicalDischarge->final_diagnosis,
                'patient_condition' => $episode->medicalDischarge->patient_condition,
                'discharge_prescription' => $episode->medicalDischarge->discharge_prescription,
                'recommendations' => $episode->medicalDischarge->recommendations,
                'follow_up_at' => $episode->medicalDischarge->follow_up_at,
                'observations' => $episode->medicalDischarge->observations,
                'transfer_destination' => $episode->medicalDischarge->transfer_destination,
                'death_occurred_at' => $episode->medicalDischarge->death_occurred_at,
                'death_place' => $episode->medicalDischarge->death_place,
                'death_causes' => $episode->medicalDischarge->death_causes,
                'discharged_at' => $episode->medicalDischarge->discharged_at,
                'created_by' => $episode->medicalDischarge->creator?->name,
            ] : null,
            // ADR-149 — le séjour en cours, s'il y en a un. L'écran le dit en
            // tête : on ne décide pas la suite d'une rencontre de la même
            // façon selon que le patient rentre chez lui ou reste au lit.
            'hospital_stay' => $stay && $user->can('hospitalization.view') ? [
                'uuid' => $stay->uuid,
                'url' => "/hospitalisation/{$stay->uuid}",
                'service' => $stay->service,
                'room_bed' => $stay->room_bed,
                'admitted_at' => $stay->admitted_at,
            ] : null,
            // La conduite à tenir : ce qui a été décidé, ce qu'il reste à
            // transmettre, et de quoi préremplir la demande sans rien
            // redemander au médecin (ADR-084).
            'consultation_orientation' => $consultation
                ? $this->presentOrientation($consultation, $user, $isActive)
                : null,
            'options' => [
                'decisions' => collect(ConsultationDecision::cases())->map(fn ($decision) => [
                    'value' => $decision->value,
                    'label' => $decision->label(),
                ])->values(),
                'clinical_priorities' => collect(ClinicalPriority::cases())->map(fn ($priority) => [
                    'value' => $priority->value,
                    'label' => $priority->label(),
                ])->values(),
                // Seules les destinations réellement autorisées au compte.
                // Le serveur revérifie de toute façon à l'écriture.
                'orientation_types' => collect(ConsultationOrientationType::cases())
                    ->filter(fn (ConsultationOrientationType $type): bool => $this->orientationApplies($type, $stay))
                    ->filter(fn (ConsultationOrientationType $type): bool => $user->can($type->permission()))
                    ->map(fn (ConsultationOrientationType $type) => [
                        'value' => $type->value,
                        'label' => $type->label(),
                        'form_title' => $type->formTitle(),
                    ])->values(),
                // ADR-108 — les feuilles de compte rendu, servies ici comme à
                // « Demandes d'examens » : la consultation ouvre la même
                // saisie, pas une version appauvrie.
                'imaging_report_templates' => $user->can('imaging_results.create') ? $templateCatalog->all() : [],
                'imaging_report_template_rights' => $templateCatalog->rightsFor($user),
                'administration_routes' => collect(AdministrationRoute::cases())->map(fn (AdministrationRoute $r) => [
                    'value' => $r->value, 'label' => $r->label(), 'short_label' => $r->shortLabel(),
                ])->values(),
                'diagnosis_types' => [
                    ['value' => DiagnosisType::Hypothesis->value, 'label' => 'Hypothèse diagnostique'],
                    ['value' => DiagnosisType::Final->value, 'label' => 'Diagnostic final'],
                ],
                // ADR-161 — un patient au lit est transféré par la conduite
                // « Référence / transfert », et son séjour se termine au départ :
                // la sortie « Transfert » ne lui est pas proposée (le serveur la
                // refuse de toute façon).
                'discharge_types' => collect(MedicalDischargeType::cases())
                    ->reject(fn (MedicalDischargeType $type): bool => $stay !== null && $type === MedicalDischargeType::Transfer)
                    ->map(fn ($type) => [
                        'value' => $type->value,
                        'label' => $type->label(),
                    ])->values(),
                'medicines' => $canViewPharmacyAvailability && $includeMedicineCatalog
                    ? $this->medicineStock->availableCatalog()
                    : [],
                'care_order_catalog' => $isActive && $user->can('care_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Care->value)
                        ->where('clinician_orderable', true)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                    : [],
                'lab_catalog' => $isActive && $user->can('laboratory_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Laboratory->value)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                    : [],
                // ADR-106 — la famille accompagne chaque examen : l'écran
                // sépare ECG et Échographie sans jamais lire un code. Un
                // examen non classé revient à `null` et reste visible dans
                // son propre groupe, plutôt que d'être rangé au hasard.
                'imaging_catalog' => $isActive && $user->can('imaging_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Imaging->value)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name', 'imaging_modality'])
                        ->map(fn (CatalogItem $item) => [
                            'uuid' => $item->uuid,
                            'code' => $item->code,
                            'name' => $item->name,
                            'modality' => $item->imaging_modality?->value,
                        ])
                    : [],
                'surgery_catalog' => $isActive && $user->can('surgery.request')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Surgery->value)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                    : [],
                'referral_destinations' => collect($referralDestinations)->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                ])->values(),
                'transfer_destinations' => $transferDestinations,
            ],
            'pending_reasons' => $pendingReasons,
            'prescription_safety' => $this->prescriptionSafety(
                $consultation,
                $isActive && $user->can('prescriptions.create') && $canViewPharmacyAvailability && $includeMedicineCatalog,
            ),
            'clinical_suggestions' => $this->clinicalSuggestions(
                $consultation,
                $isActive && $user->can('diagnoses.create'),
                $isActive && $user->can('prescriptions.create') && $canViewPharmacyAvailability,
                $user->can('clinical_protocols.manage'),
            ),
            'capabilities' => [
                'can_view_medical_record' => $canViewMedicalRecord,
                'can_update_consultation' => $isActive && $user->can('consultations.update'),
                // Validating a step and closing the encounter are the same
                // authority as writing it: no new permission is invented.
                'can_resolve_step' => $isActive && $user->can('consultations.update'),
                'can_complete_consultation' => $isActive
                    && $user->can('consultations.update')
                    && $consultation !== null,
                // ADR-096 — délibérément **hors** de `$isActive` : une
                // consultation clôturée n'est jamais « active », et exiger
                // qu'elle le soit rendrait la réouverture inatteignable. La
                // limite est ailleurs — le passage doit rester ouvert, la
                // Réception ne l'ayant pas encore clos (ADR-090).
                'can_reopen_consultation' => $consultation?->status === ConsultationStatus::Completed
                    && $episode->status === EpisodeStatus::Open
                    && $user->can('consultations.reopen'),
                // Pourquoi c'est impossible, dit par le serveur. L'écran ne
                // doit pas deviner entre « le passage est clos » et « vous
                // n'avez pas le droit » : ce sont deux impasses différentes,
                // et l'une se règle auprès de la Réception.
                'reopen_blocker' => $consultation?->status !== ConsultationStatus::Completed
                    ? null
                    : ($episode->status !== EpisodeStatus::Open
                        ? 'Le passage a été clos par la Réception : la consultation ne peut plus être rouverte.'
                        : (! $user->can('consultations.reopen')
                            ? 'Vous n’avez pas le droit de rouvrir une consultation clôturée.'
                            : null)),
                'can_create_diagnosis' => $isActive && $user->can('diagnoses.create'),
                'can_create_prescription' => $isActive
                    && $user->can('prescriptions.create')
                    && $canViewPharmacyAvailability,
                'can_view_pharmacy_availability' => $canViewPharmacyAvailability,
                'can_cancel_prescription' => $isActive && $user->can('prescriptions.cancel'),
                'can_update_prescription' => $isActive && $user->can('prescriptions.update'),
                'can_discharge' => $isActive && $user->can('medical_discharge.create'),
                'can_manage_medical_history' => $user->can('patients.medical_history.manage'),
                'can_mark_emergency' => $isActive
                    && $episode->priority !== EpisodePriority::Emergency
                    && $user->can('episodes.mark_emergency'),
                'can_create_care_order' => $isActive && $user->can('care_orders.create'),
                'can_view_care_orders' => $canViewCareOrders,
                'can_create_lab_request' => $isActive && $user->can('laboratory_orders.create'),
                'can_view_lab_requests' => $canViewLabRequests,
                'can_create_imaging_request' => $isActive && $user->can('imaging_orders.create'),
                'can_view_imaging_requests' => $canViewImagingRequests,
                'can_record_imaging_result' => $user->can('imaging_results.create'),
                'can_request_surgery' => $isActive && $user->can('surgery.request'),
                'can_request_maternity' => $isActive && $user->can('maternity.request'),
                'can_request_hospitalization' => $isActive && $user->can('hospitalization.request'),
                'can_request_transfer' => $isActive && $user->can('transfer.request'),
                'can_request_pediatrics' => $isActive && $user->can('pediatrics.request'),
                'can_defer_decision' => $isActive && $pendingReasons !== [],
            ],
        ];
    }

    /**
     * The grid before any examination exists: every system NOT_EXAMINED.
     * The screen then renders the same shape whether or not the doctor has
     * started, and no branch has to invent a status.
     *
     * @return Collection<int, array{system: ClinicalExamSystem, status: ClinicalSystemStatus, findings: ?string}>
     */
    private function emptyClinicalSystems(): Collection
    {
        return collect(ClinicalExamSystem::cases())->map(fn (ClinicalExamSystem $system): array => [
            'system' => $system,
            'status' => ClinicalSystemStatus::NotExamined,
            'findings' => null,
        ]);
    }

    /**
     * The conduite à tenir, its request, and what the request forms are
     * pre-filled with.
     *
     * The pre-fill is the whole point of §17: a doctor who has already
     * written the history, examined the patient, read the results and
     * prescribed must never retype any of it into a referral. The values
     * travel composed and stay editable — the doctor corrects, never
     * copies.
     *
     * @return array<string, mixed>
     */
    private function presentOrientation(Consultation $consultation, User $user, bool $isActive): array
    {
        $active = $this->workflow->activeOrientation($consultation);

        return [
            'active' => $active ? [
                'uuid' => $active->uuid,
                'type' => $active->type->value,
                'type_label' => $active->type->label(),
                'form_title' => $active->type->formTitle(),
                'status' => $active->status->value,
                'status_label' => $active->status->label(),
                'priority' => $active->priority?->value,
                'priority_label' => $active->priority?->label(),
                'submitted_at' => $active->submitted_at,
                'selected_by' => $active->selectedBy?->name,
                'request' => $this->presentOrientationRequest($active, $user),
            ] : null,
            // Cancelled orientations stay visible: changing course is a
            // clinical fact, and the first intention is never erased.
            'history' => $consultation->orientations()
                ->whereNotNull('cancelled_at')
                ->with('cancelledBy:id,name')
                ->get()
                ->map(fn (ConsultationOrientation $orientation) => [
                    'uuid' => $orientation->uuid,
                    'type_label' => $orientation->type->label(),
                    'cancelled_at' => $orientation->cancelled_at,
                    'cancelled_by' => $orientation->cancelledBy?->name,
                    'cancellation_reason' => $orientation->cancellation_reason,
                ])->values(),
            'can_select' => $isActive && $user->can('consultations.update'),
            'prefill' => $this->orientationPrefill($consultation),
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentOrientationRequest(ConsultationOrientation $orientation, User $user): ?array
    {
        $request = $this->orientationRequestSummary($orientation);

        if ($request === null) {
            return null;
        }

        // ADR-114 — une demande transmise se complète dans son module, jamais
        // en la retransmettant depuis la consultation : l'écran y conduit.
        return $request + $this->orientationModuleLink($orientation, $request['kind'], $user);
    }

    /** @return array{module_url: ?string, module_label: ?string} */
    private function orientationModuleLink(ConsultationOrientation $orientation, string $kind, User $user): array
    {
        $none = ['module_url' => null, 'module_label' => null];

        return match ($kind) {
            'HOSPITALIZATION' => ($stay = $orientation->hospitalizationRequest?->hospitalStay)
                && $stay->status !== HospitalStayStatus::Cancelled
                && $user->can('hospitalization.view')
                    ? ['module_url' => "/hospitalisation/{$stay->uuid}", 'module_label' => 'Hospitalisation']
                    : $none,
            'REFERRAL' => $user->can('transfers.view')
                ? ['module_url' => "/transferts/{$orientation->medicalReferral->uuid}", 'module_label' => 'Transferts']
                : $none,
            'SURGERY' => $user->can('surgery.view')
                ? ['module_url' => "/surgery/{$orientation->surgicalRequest->uuid}", 'module_label' => 'Chirurgie']
                : $none,
            'SERVICE' => match ($orientation->episodeOrientation->destination_module) {
                CatalogModule::Maternity => $user->can('maternity.view')
                    ? ['module_url' => "/maternity/orientations/{$orientation->episodeOrientation->uuid}", 'module_label' => 'Maternité']
                    : $none,
                CatalogModule::Pediatrics => $user->can('pediatrics.view')
                    ? ['module_url' => "/pediatrie/{$orientation->episodeOrientation->uuid}", 'module_label' => 'Pédiatrie']
                    : $none,
                default => $none,
            },
            default => $none,
        };
    }

    /** @return array<string, mixed>|null */
    private function orientationRequestSummary(ConsultationOrientation $orientation): ?array
    {
        if ($request = $orientation->hospitalizationRequest) {
            return [
                'kind' => 'HOSPITALIZATION',
                'uuid' => $request->uuid,
                'summary' => $request->requested_service ?: $request->reason,
                'status_label' => $request->status->label(),
                'recorded_at' => $request->requested_at,
            ];
        }

        if ($request = $orientation->medicalReferral) {
            return [
                'kind' => 'REFERRAL',
                'uuid' => $request->uuid,
                'summary' => $request->facility,
                'status_label' => $request->status->label(),
                'recorded_at' => $request->referred_at,
            ];
        }

        if ($request = $orientation->surgicalRequest) {
            return [
                'kind' => 'SURGERY',
                'uuid' => $request->uuid,
                'summary' => $request->procedure_name,
                'status_label' => $request->status->value,
                'recorded_at' => $request->created_at,
            ];
        }

        if ($request = $orientation->medicalDischarge) {
            return [
                'kind' => 'DISCHARGE',
                'uuid' => $request->uuid,
                'summary' => $request->type->label(),
                'status_label' => 'Prononcée',
                'recorded_at' => $request->discharged_at,
            ];
        }

        if ($request = $orientation->episodeOrientation) {
            return [
                'kind' => 'SERVICE',
                'uuid' => $request->uuid,
                'summary' => $request->destination_module->label(),
                'status_label' => $request->status->label(),
                'recorded_at' => $request->oriented_at,
            ];
        }

        return null;
    }

    /** @return array<string, ?string> */
    /**
     * Les examens que la Réception a déjà planifiés et dont la demande reste
     * à transmettre (ADR-109).
     *
     * Le besoin est connu depuis l'arrivée (`EpisodeServiceRequest`, ADR-030)
     * et déjà facturé (ADR-068). Faire chercher le même examen dans le
     * catalogue, c'est demander au médecin de ressaisir ce que le dossier
     * porte déjà — exactement ce que l'ADR-084 refuse pour les formulaires
     * d'orientation.
     *
     * Une ligne disparaît dès qu'une demande active la porte : ce qui est
     * transmis n'est plus à transmettre. Rien n'est déduit d'un libellé —
     * le module du `catalog_item` décide, comme partout ailleurs (ADR-052).
     *
     * @return array<int, array{catalog_item_uuid: string, name: string, code: ?string, module: string, modality: ?string, already_billed: bool}>
     */
    /**
     * ADR-111 — ce que les protocoles de la clinique proposent pour ce
     * dossier : des diagnostics tant qu'aucun n'est posé, une ordonnance dès
     * qu'il y en a un.
     *
     * Calculé à chaque affichage, jamais enregistré : une proposition n'est
     * pas un fait clinique. Ce qui est enregistré, c'est ce que le médecin en
     * retient, avec l'origine de la proposition (`suggestion_source`).
     *
     * La disponibilité en stock est jointe ici, par le même calcul que la
     * liste du catalogue : un produit épuisé reste visible — le protocole le
     * prévoit — mais n'est jamais ajoutable.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Ce que l'écran d'ordonnance doit savoir du patient pour relire une ligne
     * (ADR-128) : âge, poids relevé aux Soins et, pour chaque médicament du
     * catalogue, l'allergie connue qu'il recoupe.
     *
     * Le rapprochement est celui des propositions (`allergyConflict`) : une seule
     * règle, jamais une seconde copie qui finirait par en contredire une autre.
     * Rien ici ne juge une dose — le système n'en connaît aucune limite.
     *
     * @return array{age: ?int, weight_kg: ?float, allergy_conflicts: array<string, string>}|null
     */
    private function prescriptionSafety(?Consultation $consultation, bool $canPrescribe): ?array
    {
        if ($consultation === null || ! $canPrescribe) {
            return null;
        }

        $context = $this->protocols->context($consultation);
        $conflicts = [];

        foreach ($this->medicineStock->availableCatalog() as $medicine) {
            $substance = $this->protocols->allergyConflict(
                $context['allergies'],
                [$medicine['generic_name'] ?? null, $medicine['name'] ?? null],
            );

            if ($substance !== null) {
                $conflicts[$medicine['uuid']] = $substance;
            }
        }

        return [
            'age' => $context['age'],
            'weight_kg' => $context['weight'],
            'allergy_conflicts' => $conflicts,
        ];
    }

    private function clinicalSuggestions(
        ?Consultation $consultation,
        bool $canDiagnose,
        bool $canPrescribe,
        bool $canManageProtocols,
    ): ?array {
        if ($consultation === null || (! $canDiagnose && ! $canPrescribe)) {
            return null;
        }

        $context = $this->protocols->context($consultation);

        // Protocoles d'abord : ils sont la décision écrite de la clinique. La
        // pratique observée ne complète que ce qu'aucun protocole ne couvre.
        $protocolDiagnoses = $canDiagnose ? $this->protocols->suggestDiagnoses($consultation, $context) : [];
        $diagnoses = $canDiagnose
            ? [...$protocolDiagnoses, ...$this->practice->suggestDiagnoses(
                $consultation,
                $context,
                array_column($protocolDiagnoses, 'diagnostic_catalog_uuid'),
            )]
            : [];

        // ADR-163 — la même composition que le séjour : une seule écriture.
        $prescription = $canPrescribe
            ? $this->prescriptionSuggestions->for($context, $consultation)
            : ['groups' => [], 'excluded' => []];

        return [
            'protocol_count' => $this->protocols->activeProtocolCount(),
            // Ce que la pratique de la clinique connaît : zéro, elle ne peut
            // encore rien proposer, et l'écran doit le dire.
            'practice_cases' => $this->practice->caseCount(),
            'practice_min_cases' => ClinicPracticeIndex::MIN_CASES,
            'can_manage_protocols' => $canManageProtocols,
            'context' => [
                'age' => $context['age'],
                'sex' => $context['sex'],
                'weight' => $context['weight'],
                'allergies' => $context['allergies'],
            ],
            'diagnoses' => $diagnoses,
            'prescription' => $prescription,
        ];
    }

    private function plannedParaclinical(Consultation $consultation): array
    {
        $episode = $consultation->episode;

        if (! $episode) {
            return [];
        }

        $requested = LabRequestItem::query()
            ->whereHas('labRequest', fn ($query) => $query
                ->where('episode_id', $episode->getKey())
                ->whereNull('cancelled_at'))
            ->pluck('catalog_item_id')
            ->merge(ImagingRequestItem::query()
                ->whereHas('imagingRequest', fn ($query) => $query
                    ->where('episode_id', $episode->getKey())
                    ->whereNull('cancelled_at'))
                ->pluck('catalog_item_id'))
            ->filter()
            ->unique();

        return EpisodeServiceRequest::query()
            ->where('episode_id', $episode->getKey())
            ->whereNotIn('catalog_item_id', $requested)
            ->with('catalogItem')
            ->get()
            ->filter(fn (EpisodeServiceRequest $request): bool => in_array(
                $request->catalogItem?->module,
                [CatalogModule::Laboratory, CatalogModule::Imaging],
                true,
            ))
            ->map(fn (EpisodeServiceRequest $request): array => [
                'catalog_item_uuid' => $request->catalogItem->uuid,
                // Le libellé du catalogue, pas l'instantané : c'est celui que
                // porte la liste où l'écran doit retrouver la ligne.
                'name' => $request->catalogItem->name,
                'code' => $request->catalogItem->code,
                'module' => $request->catalogItem->module->value,
                'modality' => $request->catalogItem->imaging_modality?->value,
                // ADR-109 : l'écran le dit, pour qu'on ne craigne pas de
                // facturer deux fois en transmettant la demande.
                'already_billed' => $this->plannedBilling
                    ->unconsumedFor($episode, $request->catalogItem) !== null,
            ])
            ->values()
            ->all();
    }

    private function orientationPrefill(Consultation $consultation): array
    {
        $examination = $consultation->clinicalExamination;
        $findings = $examination
            ? $examination->systems()
                ->filter(fn (array $entry): bool => $entry['status'] === ClinicalSystemStatus::Abnormal)
                ->map(fn (array $entry): string => $entry['system']->label().' : '.trim((string) $entry['findings']))
                ->values()
                ->all()
            : [];

        $clinicalSummary = collect([
            $examination?->general_condition?->label() ? 'État général : '.$examination->general_condition->label() : null,
            $examination?->consciousness_status?->label() ? 'Conscience : '.$examination->consciousness_status->label() : null,
            ...$findings,
            $this->toPlainText($consultation->clinical_exam),
        ])->filter()->implode("\n");

        $paraclinical = $consultation->labRequests()
            ->whereNull('cancelled_at')
            ->with('items')
            ->get()
            ->flatMap(fn (LabRequest $request) => $request->items->map(
                fn ($item): string => $this->paraclinicalLine($item->catalog_item_name_snapshot, $item->result_value),
            ))
            ->merge($consultation->imagingRequests()
                ->whereNull('cancelled_at')
                ->with('items')
                ->get()
                ->flatMap(fn (ImagingRequest $request) => $request->items->map(
                    fn ($item): string => $this->paraclinicalLine($item->catalog_item_name_snapshot, $item->result_value),
                )))
            ->implode("\n");

        $treatments = $consultation->prescriptions()
            ->where('status', PrescriptionStatus::Active->value)
            ->with('lines')
            ->get()
            ->flatMap(fn ($prescription) => $prescription->lines->map(fn ($line): string => trim(collect([
                $line->medication_name,
                $line->dosage,
                $line->route?->shortLabel(),
                $line->frequency,
                $line->duration,
            ])->filter()->implode(' · '))))
            ->implode("\n");

        return [
            'reason' => $consultation->chief_complaint ?: $this->toPlainText($consultation->reason),
            'clinical_summary' => $clinicalSummary !== '' ? $clinicalSummary : null,
            'diagnosis' => $consultation->diagnoses()
                ->whereDoesntHave('cancellation')
                ->pluck('description')
                ->implode("\n") ?: null,
            'paraclinical' => $paraclinical !== '' ? $paraclinical : null,
            'treatments' => $treatments !== '' ? $treatments : null,
        ];
    }

    /**
     * The rich-text fields reach a printable letter as prose, not markup:
     * the referral is read on paper by someone with no browser.
     */
    /**
     * Une ligne de résultat paraclinique, en texte.
     *
     * Un compte rendu d'imagerie est saisi en éditeur riche et stocké en HTML
     * (ADR-070) ; cette ligne rejoint un `<textarea>`, où le balisage
     * s'affiche tel quel. Le préremplissage montrait donc au médecin
     * « UTERUS<p>• Orientation… </p><p>• Volume… » — et c'est ce texte-là
     * qui serait parti au service d'accueil.
     */
    private function paraclinicalLine(string $name, ?string $result): string
    {
        $text = $this->toPlainText($result);

        if ($text === null) {
            return trim($name).' — en attente';
        }

        // Un compte rendu tient sur plusieurs lignes : il est présenté sous
        // son examen plutôt que collé derrière, sinon la première ligne
        // absorbe le nom et les suivantes flottent sans rattachement.
        return str_contains($text, "\n")
            ? trim($name)." :\n".$text
            : trim($name).' : '.$text;
    }

    /**
     * Du HTML de l'éditeur riche vers du texte lisible.
     *
     * Seuls `</p>` et `<br>` produisaient un retour à la ligne : une liste à
     * puces ou des titres se retrouvaient collés en une seule phrase. Chaque
     * fin de bloc en produit un désormais, et les lignes vides consécutives
     * sont réduites — un compte rendu doit rester relisible, pas fidèle à
     * une mise en page qu'un champ de texte ne rend pas.
     */
    private function toPlainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $withBreaks = preg_replace(
            [
                '/<br\s*\/?>/i',
                // L'ouverture compte autant que la fermeture : un compte rendu
                // écrit « UTERUS<p>• Orientation… » sans fermer avant, et seule
                // la fermeture cassant la ligne, les deux restaient collés.
                '/<(p|div|li|h[1-6]|tr|blockquote)(\s[^>]*)?>/i',
                '/<\/(p|div|li|h[1-6]|tr|blockquote)\s*>/i',
            ],
            "\n",
            $html,
        ) ?? $html;

        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5);
        // Espaces insécables compris : l'éditeur en produit, et `trim()` seul
        // les laisse en début de ligne.
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        // Une ligne vide sur deux : ouverture *et* fermeture d'un même bloc
        // cassent la ligne, et un champ de texte n'a pas d'interlignage à
        // restituer. Le compte rendu se lit d'un bloc, ligne à ligne.
        $text = trim(preg_replace('/\n{2,}/', "\n", $text) ?? $text);

        return $text !== '' ? $text : null;
    }

    /**
     * ADR-149 — la conduite à tenir dépend de là où le patient se trouve.
     *
     * Hospitalisé, une visite encore ouverte se conclut par « Poursuite de
     * l'hospitalisation » : depuis l'ADR-162, la sortie d'un patient au lit se
     * prononce sur la page du séjour, et « Hospitalisation » ouvrirait un
     * **second séjour** sur le même passage.
     *
     * Non hospitalisé, « Poursuite de l'hospitalisation » ne veut rien dire.
     */
    private function orientationApplies(ConsultationOrientationType $type, ?HospitalStay $stay): bool
    {
        if ($stay === null) {
            return $type !== ConsultationOrientationType::ContinuedHospitalization;
        }

        // ADR-162 — pour un patient au lit, la sortie se prononce sur la page
        // du séjour, et là seulement ; « Hospitalisation » ouvrirait un
        // second séjour.
        return ! in_array($type, [
            ConsultationOrientationType::Hospitalization,
            ConsultationOrientationType::Discharge,
        ], true);
    }
}
