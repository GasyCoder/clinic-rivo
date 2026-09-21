<?php

namespace App\Http\Controllers;

use App\Actions\Hospitalization\CancelHospitalVisitAction;
use App\Actions\Hospitalization\CancelSurgeryFromStayAction;
use App\Actions\Hospitalization\CorrectHospitalStayLocationAction;
use App\Actions\Hospitalization\MoveHospitalStayAction;
use App\Actions\Hospitalization\RecordHospitalDietEntryAction;
use App\Actions\Hospitalization\RecordHospitalStayDiagnosisAction;
use App\Actions\Hospitalization\RecordVitalSignReadingAction;
use App\Actions\Hospitalization\RequestSurgeryFromStayAction;
use App\Actions\Hospitalization\UpdateHospitalizationRequestAction;
use App\Actions\Medicine\CompleteConsultationAction;
use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\HospitalCareLevel;
use App\Enums\HospitalStayStatus;
use App\Enums\SurgicalRequestStatus;
use App\Http\Requests\Hospitalization\MoveHospitalStayRequest;
use App\Http\Requests\Hospitalization\RequestSurgeryFromStayRequest;
use App\Http\Requests\Hospitalization\StoreHospitalDietEntryRequest;
use App\Http\Requests\Hospitalization\StoreHospitalStayDiagnosisRequest;
use App\Http\Requests\Hospitalization\StoreVitalSignReadingRequest;
use App\Http\Requests\Hospitalization\UpdateHospitalizationRequestRequest;
use App\Http\Requests\Hospitalization\UpdateHospitalStayRequest;
use App\Models\CatalogItem;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\EpisodeOrientation;
use App\Models\HospitalDietEntry;
use App\Models\HospitalStay;
use App\Models\SurgicalRequest;
use App\Models\User;
use App\Models\VitalSignReading;
use App\Support\ConsultationWorkflow;
use App\Support\Hospitalization\BedDirectory;
use App\Support\Hospitalization\DietSheet;
use App\Support\Hospitalization\HospitalStayWorkstation;
use App\Support\HospitalStaySurveillance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-113 — l'espace Hospitalisation.
 *
 * Il réunit les patients hospitalisés : le séjour commence à la demande du
 * médecin (admission automatique) et se termine par sa sortie médicale. La
 * fiche de régime s'y tient jour par jour. Cet espace n'encaisse rien et ne
 * facture aucun repas.
 */
class HospitalizationController extends Controller
{
    public function index(Request $request, BedDirectory $beds): Response
    {
        $search = trim((string) $request->query('q', ''));
        $bedsConfigured = $beds->configured();

        // ADR-156 — le module liste les patients **hospitalisés**. Les sorties
        // appartiennent à la Réception (« Sorties & règlements », ADR-090) : un
        // onglet « Sortis » ici en faisait une seconde liste des sorties, pour
        // un séjour que plus personne n'a à traiter. Une recherche nommée
        // retrouve tout de même un séjour terminé — sa fiche de régime et son
        // dossier restent consultables.
        $base = HospitalStay::query()->where('status', '!=', HospitalStayStatus::Cancelled->value);

        $counts = [
            'active' => (clone $base)->where('status', HospitalStayStatus::Active->value)->count(),
        ];

        $stays = $base
            ->when($search === '', fn ($query) => $query->where('status', HospitalStayStatus::Active->value))
            ->with([
                'episode.patient:id,uuid,patient_number,first_name,last_name,sex,birth_date,declared_age',
                'hospitalizationRequest:id,reason,priority,requested_by',
                'hospitalizationRequest.requestedBy:id,name',
                'medicalDischarge:id,type',
                'currentMovement',
            ])
            ->withCount('dietEntries')
            ->when($search !== '', fn ($query) => $query->whereHas('episode', fn ($episode) => $episode
                ->where('episode_number', 'like', "%{$search}%")
                ->orWhereHas('patient', fn ($patient) => $patient
                    ->where('patient_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->orderByDesc('admitted_at')
            ->paginate(20)
            ->withQueryString();

        $stays->through(fn (HospitalStay $stay): array => [
            'uuid' => $stay->uuid,
            'episode_uuid' => $stay->episode->uuid,
            'episode_number' => $stay->episode->episode_number,
            'patient' => $this->patient($stay),
            'reason' => $stay->hospitalizationRequest?->reason,
            'priority' => $stay->hospitalizationRequest?->priority?->value,
            'requested_by' => $stay->hospitalizationRequest?->requestedBy?->name,
            'service' => $stay->service,
            'room_bed' => $stay->room_bed,
            'care_level' => $stay->currentMovement?->care_level?->value,
            'care_level_label' => $stay->currentMovement?->care_level?->label(),
            // ADR-164 — un patient au lit sans lit attribué se voit dans la liste.
            'needs_bed' => $bedsConfigured && $stay->isActive() && $stay->hospital_bed_id === null,
            'admitted_at' => $stay->admitted_at,
            'discharged_at' => $stay->discharged_at,
            'discharge_type' => $stay->end_reason?->label() ?? $stay->medicalDischarge?->type?->label(),
            'diet_entries_count' => $stay->diet_entries_count,
        ]);

        $user = $request->user();

        return Inertia::render('Hospitalization/Index', [
            'stays' => $stays,
            'counts' => $counts,
            'search' => $search,
            // ADR-165 — les actions groupées que ce compte peut lancer. Le serveur
            // revérifie chaque droit : ceci ne sert qu'à ne pas proposer un refus.
            'capabilities' => [
                'can_print_medical_records' => $user->can('patients.view'),
                'can_export' => $user->can('hospitalization.export'),
                'can_view_vitals' => $user->can('vitals.view'),
            ],
            'bulkLimit' => HospitalStaySelectionController::BULK_LIMIT,
            // ADR-164 — le plan des lits : qui occupe quoi, ce qui est libre,
            // et les patients encore sans lit. Absent tant que le site n'a
            // configuré aucun lit — jamais un plan vide qui se lirait « complet ».
            // Le drapeau, lui, part toujours : l'onglet dit alors où les lits
            // se créent, au lieu de disparaître comme une fonction absente.
            'bedsConfigured' => $bedsConfigured,
            'beds' => $bedsConfigured ? [
                'summary' => $beds->summary(),
                'services' => $beds->tree(withPatients: true),
                'unassigned' => HospitalStay::query()
                    ->where('status', HospitalStayStatus::Active->value)
                    ->whereNull('hospital_bed_id')
                    ->with('episode:id,episode_number,patient_id', 'episode.patient:id,first_name,last_name')
                    ->orderBy('admitted_at')
                    ->get(['id', 'uuid', 'episode_id', 'service', 'admitted_at'])
                    ->map(fn (HospitalStay $stay): array => [
                        'stay_uuid' => $stay->uuid,
                        'episode_number' => $stay->episode?->episode_number,
                        'patient' => trim(($stay->episode?->patient?->last_name ?? '').' '.($stay->episode?->patient?->first_name ?? '')),
                        'service' => $stay->service,
                        'admitted_at' => $stay->admitted_at,
                    ])
                    ->all(),
            ] : null,
        ]);
    }

    public function show(
        Request $request,
        HospitalStay $hospitalStay,
        HospitalStaySurveillance $surveillance,
        HospitalStayWorkstation $workstation,
        BedDirectory $beds,
    ): Response {
        abort_if($hospitalStay->status === HospitalStayStatus::Cancelled, 404);

        $user = $request->user();

        return Inertia::render('Hospitalization/Show', [
            // ADR-162 — le poste de travail : note du jour, ordonnances,
            // examens, soins, transfert, avec leurs catalogues.
            ...$workstation->present($hospitalStay, $user),
            'stay' => $this->stay($hospitalStay),
            // ADR-161 — l'historique des emplacements et la surveillance répétée.
            'movements' => $surveillance->movements($hospitalStay),
            'vitalReadings' => $user->can('vitals.view') ? $surveillance->readings($hospitalStay) : [],
            // ADR-164 — dès que le site a configuré ses lits, l'emplacement se
            // choisit parmi les lits libres ; sinon il reste saisi à la main.
            'bedsConfigured' => $bedsConfigured = $beds->configured(),
            'freeBeds' => $bedsConfigured && $hospitalStay->isActive() && $user->can('hospitalization.update')
                ? $beds->freeBeds()
                : [],
            'careLevels' => collect(HospitalCareLevel::cases())
                ->map(fn (HospitalCareLevel $level): array => ['value' => $level->value, 'label' => $level->label()])
                ->all(),
            // ADR-147 — ce que le dossier a déjà conclu, plus ce que le séjour
            // a conclu : le médecin ne ressaisit rien pour prononcer la sortie.
            'diagnoses' => $user->can('diagnoses.view')
                ? $this->diagnoses($hospitalStay)
                : [],
            // ADR-163 — les consultations du passage encore ouvertes : tant
            // qu'elles le sont, le passage n'atteint pas « Sorties & règlements ».
            // L'écran dit lesquelles, ce qui manque pour les clôturer, et les
            // clôture d'un clic quand plus rien ne manque.
            'openConsultations' => $user->can('consultations.view')
                ? $this->openConsultations($hospitalStay, $user)
                : [],
            // ADR-160 — ce que le séjour a envoyé au bloc, et où en est chaque
            // demande. Le fait est du parcours, servi à qui lit le séjour ; le
            // lien vers le dossier du bloc n'est proposé qu'avec `surgery.view`.
            'surgeries' => $this->surgeries($hospitalStay, $user),
            'surgeryProcedures' => $user->can('surgery.request') && $hospitalStay->isActive()
                ? $this->surgeryProcedures()
                : [],
            'capabilities' => [
                'can_record_diet' => $user->can('hospital_diet.record')
                    && $hospitalStay->episode->status === EpisodeStatus::Open,
                'can_add_diet' => $user->can('hospital_diet.record') && $hospitalStay->isActive(),
                'can_update_stay' => $user->can('hospitalization.update') && $hospitalStay->isActive(),
                'can_edit_request' => $user->can('hospitalization.request') && $hospitalStay->isActive(),
                'can_discharge' => $user->can('medical_discharge.create') && $hospitalStay->isActive(),
                'can_add_diagnosis' => $user->can('diagnoses.create') && $hospitalStay->isActive(),
                'can_request_surgery' => $user->can('surgery.request') && $hospitalStay->isActive(),
                'can_move' => $user->can('hospitalization.update') && $hospitalStay->isActive(),
                'can_view_vitals' => $user->can('vitals.view'),
                'can_record_vitals' => $user->can('vitals.create') && $hospitalStay->isActive(),
                'vitals_record_block' => $this->vitalsRecordBlock($user, $hospitalStay),
                'can_correct_vitals' => $user->can('vitals.update')
                    && $hospitalStay->episode->status === EpisodeStatus::Open,
            ],
        ]);
    }

    /**
     * ADR-161 — corriger l'emplacement actuel, sans mutation ; ADR-164 —
     * attribuer le premier lit après l'admission automatique.
     */
    public function update(
        UpdateHospitalStayRequest $request,
        HospitalStay $hospitalStay,
        CorrectHospitalStayLocationAction $action,
    ): RedirectResponse {
        $stay = $action->execute($hospitalStay, $request->validated(), $request->user());

        return back()->with('status', $stay->hospital_bed_id
            ? "Patient installé : {$stay->room_bed}."
            : 'Séjour mis à jour.');
    }

    public function updateRequest(
        UpdateHospitalizationRequestRequest $request,
        HospitalStay $hospitalStay,
        UpdateHospitalizationRequestAction $action,
    ): RedirectResponse {
        $action->execute($hospitalStay, $request->validated());

        return back()->with('status', 'Demande d’hospitalisation complétée.');
    }

    /**
     * ADR-160 — le patient au lit descend au bloc, et garde son lit. La
     * demande naît du séjour ; le bloc la programme.
     */
    public function requestSurgery(
        RequestSurgeryFromStayRequest $request,
        HospitalStay $hospitalStay,
        RequestSurgeryFromStayAction $action,
    ): RedirectResponse {
        $surgicalRequest = $action->execute($hospitalStay, $request->validated(), $request->user());

        return back()->with('status', "Transféré au bloc : {$surgicalRequest->procedure_name}. Le patient garde son lit.");
    }

    /**
     * ADR-163 — revenir sur un « Transférer au bloc » tant que le bloc ne l'a
     * pas programmé. Le patient n'a jamais quitté son lit.
     */
    public function cancelSurgery(
        Request $request,
        HospitalStay $hospitalStay,
        SurgicalRequest $surgicalRequest,
        CancelSurgeryFromStayAction $action,
    ): RedirectResponse {
        abort_unless($surgicalRequest->episode_id === $hospitalStay->episode_id, 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->execute($hospitalStay, $surgicalRequest, $validated['reason'] ?? null, $request->user());

        return back()
            ->with('status', "Transfert au bloc annulé : {$surgicalRequest->procedure_name}. Le patient reste dans son lit.")
            ->with('status_type', 'warning');
    }

    /**
     * ADR-163 — annuler une visite de service restée ouverte. Elle n'est pas
     * effacée : elle reste lisible, statut « Annulée ».
     */
    public function cancelVisit(
        Request $request,
        HospitalStay $hospitalStay,
        EpisodeOrientation $episodeOrientation,
        CancelHospitalVisitAction $action,
    ): RedirectResponse {
        abort_unless($episodeOrientation->episode_id === $hospitalStay->episode_id, 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $action->execute($hospitalStay, $episodeOrientation, $validated['reason'] ?? null, $request->user());

        return back()
            ->with('status', 'Visite de service annulée. Le patient reste hospitalisé.')
            ->with('status_type', 'warning');
    }

    /**
     * ADR-163 — clôturer depuis le séjour une consultation du passage restée
     * ouverte, quand plus rien ne manque. La règle est celle de la clôture
     * (ADR-076, ADR-084) : l'action refuse de toute façon si un obstacle existe.
     */
    public function closeConsultation(
        Request $request,
        HospitalStay $hospitalStay,
        EpisodeOrientation $episodeOrientation,
        CompleteConsultationAction $action,
    ): RedirectResponse {
        abort_unless(
            $episodeOrientation->episode_id === $hospitalStay->episode_id
                && $episodeOrientation->destination_module === CatalogModule::Medicine,
            404,
        );
        $consultation = $episodeOrientation->consultation()->first();
        abort_unless($consultation !== null, 404);

        $action->execute($consultation, $request->user());

        return back()->with('status', 'Consultation clôturée.');
    }

    /** ADR-161 — changement de service, de lit ou de niveau de soins. */
    public function move(MoveHospitalStayRequest $request, HospitalStay $hospitalStay, MoveHospitalStayAction $action): RedirectResponse
    {
        $movement = $action->execute($hospitalStay, $request->validated(), $request->user());

        return back()->with('status', 'Patient déplacé : '.$movement->care_level->label().($movement->service ? " · {$movement->service}" : '').'.');
    }

    /** ADR-161 — un relevé de surveillance. */
    public function storeReading(StoreVitalSignReadingRequest $request, HospitalStay $hospitalStay, RecordVitalSignReadingAction $action): RedirectResponse
    {
        $action->record($hospitalStay, $request->validated(), $request->user());

        return back()->with('status', 'Relevé enregistré.');
    }

    /** ADR-161 — corriger un relevé ; l'ancienne valeur reste à l'audit. */
    public function updateReading(
        StoreVitalSignReadingRequest $request,
        HospitalStay $hospitalStay,
        VitalSignReading $vitalSignReading,
        RecordVitalSignReadingAction $action,
    ): RedirectResponse {
        abort_unless($vitalSignReading->hospital_stay_id === $hospitalStay->getKey(), 404);

        $action->correct($vitalSignReading, $request->validated(), $request->user());

        return back()->with('status', 'Relevé corrigé.');
    }

    /**
     * ADR-147 — le diagnostic conclu au terme du séjour.
     *
     * Il rejoint le séjour, jamais la consultation qui a demandé
     * l'hospitalisation : elle est le plus souvent close (ADR-076).
     */
    public function storeDiagnosis(
        StoreHospitalStayDiagnosisRequest $request,
        HospitalStay $hospitalStay,
        RecordHospitalStayDiagnosisAction $action,
    ): RedirectResponse {
        $action->execute(
            $hospitalStay,
            $request->validated('description'),
            $request->user(),
            $request->validated('diagnostic_catalog_uuid'),
            $request->validated('notes'),
        );

        return back()->with('status', 'Diagnostic ajouté au séjour.');
    }

    public function storeDiet(
        StoreHospitalDietEntryRequest $request,
        HospitalStay $hospitalStay,
        RecordHospitalDietEntryAction $action,
    ): RedirectResponse {
        $action->execute($hospitalStay, $request->validated(), $request->user());

        return back()->with('status', 'Ligne ajoutée à la fiche de régime.');
    }

    public function updateDiet(
        StoreHospitalDietEntryRequest $request,
        HospitalStay $hospitalStay,
        HospitalDietEntry $hospitalDietEntry,
        RecordHospitalDietEntryAction $action,
    ): RedirectResponse {
        $action->execute($hospitalStay, $request->validated(), $request->user(), $hospitalDietEntry);

        return back()->with('status', 'Ligne de la fiche de régime corrigée.');
    }

    public function printDiet(HospitalStay $hospitalStay, DietSheet $sheet): Response
    {
        abort_if($hospitalStay->status === HospitalStayStatus::Cancelled, 404);

        return Inertia::render('Hospitalization/DietSheetPrint', [
            'stay' => $sheet->present($hospitalStay),
        ]);
    }

    /** @return array<string, mixed> */
    private function stay(HospitalStay $stay): array
    {
        $stay->load([
            'episode.patient.allergies' => fn ($query) => $query->orderBy('substance'),
            'episode.careRecord:id,episode_id,smoker',
            'hospitalizationRequest.requestedBy:id,name',
            'admittedBy:id,name',
            'dischargedBy:id,name',
            'medicalDischarge',
            'bed:id,uuid',
            'medicalReferral:id,facility,departed_at',
            'currentMovement',
            'dietEntries' => fn ($query) => $query->orderBy('served_on')->orderBy('served_time')->orderBy('id'),
            'dietEntries.recordedBy:id,name',
            'dietEntries.updatedBy:id,name',
        ]);

        $request = $stay->hospitalizationRequest;
        $smoker = $stay->episode->careRecord?->smoker;

        return [
            'uuid' => $stay->uuid,
            'status' => $stay->status->value,
            'status_label' => $stay->status->label(),
            'episode' => [
                'uuid' => $stay->episode->uuid,
                'episode_number' => $stay->episode->episode_number,
                'url' => "/passages/{$stay->episode->uuid}",
            ],
            'patient' => $this->patient($stay),
            // La fiche reprend ce que le dossier sait déjà : rien n'est
            // ressaisi (§17). Une absence reste une absence : le tabac non
            // renseigné n'est jamais écrit « Non ».
            'allergies' => $stay->episode->patient->allergies
                ->map(fn ($allergy) => $allergy->substance)
                ->filter()
                ->values(),
            'smoker' => $smoker === null ? null : (bool) $smoker,
            'request' => [
                'reason' => $request?->reason,
                'admission_diagnosis' => $request?->admission_diagnosis,
                'clinical_summary' => $request?->clinical_summary,
                'planned_treatment' => $request?->planned_treatment,
                'instructions' => $request?->instructions,
                'priority' => $request?->priority?->value,
                'requested_by' => $request?->requestedBy?->name,
                'requested_at' => $request?->requested_at,
            ],
            'service' => $stay->service,
            'room_bed' => $stay->room_bed,
            // ADR-164 — le lit du référentiel, quand le site en a configuré.
            'bed_uuid' => $stay->bed?->uuid,
            'admitted_at' => $stay->admitted_at,
            'admitted_by' => $stay->admittedBy?->name,
            'discharged_at' => $stay->discharged_at,
            'discharged_by' => $stay->dischargedBy?->name,
            // ADR-161 — comment le séjour s'est terminé, y compris par un départ
            // en transfert, qui ne porte aucune sortie médicale.
            'end_reason' => $stay->end_reason?->value,
            'end_reason_label' => $stay->end_reason?->label(),
            'transfer' => $stay->medicalReferral ? [
                'facility' => $stay->medicalReferral->facility,
                'departed_at' => $stay->medicalReferral->departed_at,
            ] : null,
            'care_level' => $stay->currentMovement?->care_level?->value,
            'care_level_label' => $stay->currentMovement?->care_level?->label(),
            'discharge' => $stay->medicalDischarge ? [
                'type' => $stay->medicalDischarge->type->value,
                'type_label' => $stay->medicalDischarge->type->label(),
                'final_diagnosis' => $stay->medicalDischarge->final_diagnosis,
                'patient_condition' => $stay->medicalDischarge->patient_condition,
            ] : null,
            'diet_entries' => $stay->dietEntries->map(fn (HospitalDietEntry $entry): array => [
                'uuid' => $entry->uuid,
                'served_on' => $entry->served_on?->toDateString(),
                'served_time' => $entry->served_time,
                'tea_bread' => $entry->tea_bread,
                'sosoa_brochette' => $entry->sosoa_brochette,
                'yogurt' => $entry->yogurt,
                'puree' => $entry->puree,
                'observation' => $entry->observation,
                'recorded_by' => $entry->recordedBy?->name,
                'updated_by' => $entry->updatedBy?->name,
            ])->values(),
        ];
    }

    /**
     * Les diagnostics que la sortie peut cocher : ceux du passage et ceux du séjour.
     *
     * Les deux sont des faits cliniques déjà consignés ; le formulaire les coche
     * d'office et le médecin décide lesquels portent la conclusion. Un diagnostic
     * annulé par son auteur (ADR-035) n'en est plus un et n'est pas servi.
     *
     * @return list<array<string, mixed>>
     */
    private function diagnoses(HospitalStay $stay): array
    {
        $fromConsultations = Diagnosis::query()
            ->whereHas('consultation', fn ($query) => $query->where('episode_id', $stay->episode_id))
            ->whereDoesntHave('cancellation')
            ->with('recordedBy:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (Diagnosis $diagnosis): array => [
                'id' => 'consultation:'.$diagnosis->getKey(),
                'description' => $diagnosis->description,
                'origin' => 'CONSULTATION',
                'recorded_by' => $diagnosis->recordedBy?->name,
                'recorded_at' => $diagnosis->created_at,
            ]);

        $fromStay = $stay->diagnoses()
            ->with('recordedBy:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($diagnosis): array => [
                'id' => 'stay:'.$diagnosis->uuid,
                'description' => $diagnosis->description,
                'origin' => 'STAY',
                'recorded_by' => $diagnosis->recordedBy?->name,
                'recorded_at' => $diagnosis->created_at,
            ]);

        return $fromConsultations->concat($fromStay)->values()->all();
    }

    /**
     * ADR-161 — pourquoi cet écran ne propose pas d'ajouter un relevé.
     *
     * Un cadre vide sans un mot se lit « la surveillance ne sert à rien ici » :
     * il doit dire qui relève et, le cas échéant, quel droit manque — la même
     * règle que les onglets verrouillés de l'ADR-158 et le refus de l'ADR-154.
     * `null` quand le relevé est possible : l'écran affiche alors le formulaire.
     */
    private function vitalsRecordBlock(User $user, HospitalStay $stay): ?string
    {
        if (! $stay->isActive()) {
            return 'Le séjour est terminé : la surveillance est close. Les relevés déjà pris restent lisibles.';
        }

        if (! $user->can('vitals.create')) {
            return 'Les relevés sont pris au lit du patient par l’équipe soignante. Ajouter un relevé demande le droit « vitals.create », qui s’accorde dans Rôles & permissions.';
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function surgeries(HospitalStay $stay, User $user): array
    {
        $canOpen = $user->can('surgery.view');
        // ADR-163 — retirer une demande est la même autorité que la faire.
        $canCancel = $user->can('surgery.request') && $stay->episode->status === EpisodeStatus::Open;

        return SurgicalRequest::query()
            ->where('episode_id', $stay->episode_id)
            ->with(['surgeon:id,name', 'requestedBy:id,name', 'cancelledBy:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (SurgicalRequest $surgery): array => [
                'uuid' => $surgery->uuid,
                'procedure_name' => $surgery->procedure_name,
                'status' => $surgery->status->value,
                'origin' => $surgery->origin?->value,
                'origin_label' => $surgery->origin?->label(),
                'requested_by' => $surgery->requestedBy?->name,
                'surgeon' => $surgery->surgeon?->name,
                'scheduled_at' => $surgery->scheduled_at,
                'created_at' => $surgery->created_at,
                'cancelled_at' => $surgery->cancelled_at,
                'cancelled_by' => $surgery->cancelledBy?->name,
                'cancellation_reason' => $surgery->cancellation_reason,
                'url' => $canOpen ? "/surgery/{$surgery->uuid}" : null,
                // Annulable tant que le bloc ne l'a pas programmée (choix du
                // propriétaire) ; une demande de la Réception ou de la
                // Maternité se retire là où elle a été faite.
                'can_cancel' => $canCancel
                    && $surgery->status === SurgicalRequestStatus::Pending
                    && CancelSurgeryFromStayAction::cancellableOrigin($surgery),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function surgeryProcedures(): array
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Surgery->value)
            ->where('code', 'like', 'SURG-%')
            ->whereNull('deleted_at')
            ->orderByRaw("code = 'SURG-OTHER'")
            ->orderBy('name')
            ->get(['uuid', 'code', 'name'])
            ->map(fn (CatalogItem $item): array => ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name])
            ->all();
    }

    /**
     * ADR-163 — les consultations du passage encore ouvertes, avec ce qui manque
     * pour les clôturer.
     *
     * La consultation qui a demandé l'hospitalisation, une visite de service
     * d'avant l'ADR-162 : tant qu'une seule reste ouverte, un service a encore
     * le patient, et le passage n'atteint pas « Sorties & règlements » après la
     * sortie (ADR-054, ADR-084). Rien n'est clôturé à la place du médecin
     * (ADR-076) : l'écran nomme ce qui manque, et clôture d'un clic quand plus
     * rien ne manque.
     *
     * C'est la seule trace des visites sur cette page (ADR-163) : plus aucune
     * ne s'ouvre, et celles qui sont closes ou annulées se relisent sur la page
     * du passage, avec le reste du dossier.
     *
     * @return list<array<string, mixed>>
     */
    private function openConsultations(HospitalStay $stay, User $user): array
    {
        $workflow = app(ConsultationWorkflow::class);
        $canClose = $user->can('consultations.update');
        $canCancelVisit = $user->can('consultations.create') && $stay->episode->status === EpisodeStatus::Open;

        return Consultation::query()
            ->where('episode_id', $stay->episode_id)
            ->whereIn('status', ConsultationStatus::editableValues())
            ->whereHas('orientation', fn ($query) => $query->where('destination_module', CatalogModule::Medicine->value))
            ->with(['orientation:id,uuid,source_module,destination_module,accepted_by', 'doctor:id,name'])
            ->orderBy('id')
            ->get()
            ->map(function (Consultation $consultation) use ($stay, $user, $workflow, $canClose, $canCancelVisit): array {
                $orientation = $consultation->orientation;
                $isVisit = CancelHospitalVisitAction::isVisit($orientation);
                $isAdmission = ! $isVisit && $stay->hospitalization_request_id !== null
                    && $consultation->orientations()->where('hospitalization_request_id', $stay->hospitalization_request_id)->exists();
                $blockers = $workflow->closureBlockerMessages($consultation);
                // ADR-163 — une visite de service ne se clôture pas toujours :
                // ouverte par erreur, elle s'annule tant qu'elle n'a rien
                // produit. Ce qui l'en empêche est dit avant le clic.
                $cancelBlockers = $isVisit ? CancelHospitalVisitAction::blockers($consultation) : [];
                $mine = $orientation->accepted_by === null || $orientation->accepted_by === $user->getKey();

                return [
                    'uuid' => $orientation->uuid,
                    'kind' => $isVisit ? 'VISIT' : ($isAdmission ? 'ADMISSION' : 'CONSULTATION'),
                    'kind_label' => $isVisit
                        ? 'Visite de service'
                        : ($isAdmission ? 'Consultation qui a demandé l’hospitalisation' : 'Consultation'),
                    'doctor' => $consultation->doctor?->name,
                    'consulted_at' => $consultation->consulted_at,
                    'url' => "/medicine/orientations/{$orientation->uuid}/cloture",
                    'closure_blockers' => $blockers,
                    'can_close' => $canClose && $blockers === [],
                    'cancel_blockers' => $cancelBlockers,
                    'can_cancel' => $isVisit && $canCancelVisit && $mine && $cancelBlockers === [],
                    'cancel_reserved_to_author' => $isVisit && ! $mine,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function patient(HospitalStay $stay): array
    {
        $patient = $stay->episode->patient;

        return [
            'uuid' => $patient?->uuid,
            'patient_number' => $patient?->patient_number,
            'first_name' => $patient?->first_name,
            'last_name' => $patient?->last_name,
            'name' => trim(($patient?->last_name ?? '').' '.($patient?->first_name ?? '')),
            'sex' => $patient?->sex?->value ?? $patient?->sex,
            'age' => $patient?->birth_date?->age ?? $patient?->declared_age,
        ];
    }
}
