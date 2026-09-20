<?php

namespace App\Http\Controllers;

use App\Actions\Hospitalization\RecordHospitalDietEntryAction;
use App\Actions\Hospitalization\RecordHospitalStayDiagnosisAction;
use App\Actions\Hospitalization\StartHospitalVisitAction;
use App\Actions\Hospitalization\UpdateHospitalizationRequestAction;
use App\Enums\EpisodeStatus;
use App\Enums\HospitalStayStatus;
use App\Http\Requests\Hospitalization\StoreHospitalDietEntryRequest;
use App\Http\Requests\Hospitalization\StoreHospitalStayDiagnosisRequest;
use App\Http\Requests\Hospitalization\UpdateHospitalizationRequestRequest;
use App\Http\Requests\Hospitalization\UpdateHospitalStayRequest;
use App\Models\HospitalDietEntry;
use App\Enums\CatalogModule;
use App\Models\Consultation;
use App\Models\Diagnosis;
use App\Models\HospitalStay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

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
            'episode_number' => $stay->episode->episode_number,
            'patient' => $this->patient($stay),
            'reason' => $stay->hospitalizationRequest?->reason,
            'priority' => $stay->hospitalizationRequest?->priority?->value,
            'requested_by' => $stay->hospitalizationRequest?->requestedBy?->name,
            'service' => $stay->service,
            'room_bed' => $stay->room_bed,
            'admitted_at' => $stay->admitted_at,
            'discharged_at' => $stay->discharged_at,
            'discharge_type' => $stay->medicalDischarge?->type?->label(),
            'diet_entries_count' => $stay->diet_entries_count,
        ]);

        return Inertia::render('Hospitalization/Index', [
            'stays' => $stays,
            'counts' => $counts,
            'search' => $search,
        ]);
    }

    public function show(Request $request, HospitalStay $hospitalStay): Response
    {
        abort_if($hospitalStay->status === HospitalStayStatus::Cancelled, 404);

        $user = $request->user();

        return Inertia::render('Hospitalization/Show', [
            'stay' => $this->stay($hospitalStay),
            // ADR-147 — ce que le dossier a déjà conclu, plus ce que le séjour
            // a conclu : le médecin ne ressaisit rien pour prononcer la sortie.
            'diagnoses' => $user->can('diagnoses.view')
                ? $this->diagnoses($hospitalStay)
                : [],
            // ADR-148 — les visites de service déjà faites pendant le séjour.
            'visits' => $user->can('consultations.view')
                ? $this->visits($hospitalStay)
                : [],
            'capabilities' => [
                'can_record_diet' => $user->can('hospital_diet.record')
                    && $hospitalStay->episode->status === EpisodeStatus::Open,
                'can_add_diet' => $user->can('hospital_diet.record') && $hospitalStay->isActive(),
                'can_update_stay' => $user->can('hospitalization.update') && $hospitalStay->isActive(),
                'can_edit_request' => $user->can('hospitalization.request') && $hospitalStay->isActive(),
                'can_discharge' => $user->can('medical_discharge.create') && $hospitalStay->isActive(),
                'can_add_diagnosis' => $user->can('diagnoses.create') && $hospitalStay->isActive(),
                // ADR-148 — ouvrir une visite de service : la même autorité que
                // prendre un patient en charge en Médecine.
                'can_open_visit' => $user->can('consultations.create') && $hospitalStay->isActive(),
                'can_view_visits' => $user->can('consultations.view'),
            ],
        ]);
    }

    public function update(UpdateHospitalStayRequest $request, HospitalStay $hospitalStay): RedirectResponse
    {
        if (! $hospitalStay->isActive()) {
            throw ValidationException::withMessages(['room_bed' => 'Le séjour est terminé.']);
        }

        $clean = static fn (mixed $value): ?string => trim((string) $value) ?: null;

        $hospitalStay->update([
            'room_bed' => $clean($request->validated('room_bed')),
            'service' => $clean($request->validated('service')),
        ]);

        return back()->with('status', 'Séjour mis à jour.');
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
     * ADR-148 — ouvrir une visite de service.
     *
     * Elle réutilise l'assistant Médecine tel quel : le médecin y retrouve
     * diagnostic, ordonnance, examens et ordre de soins, avec leurs droits et
     * leur facturation d'aujourd'hui. Aucun circuit n'est dupliqué.
     */
    public function openVisit(
        Request $request,
        HospitalStay $hospitalStay,
        StartHospitalVisitAction $action,
    ): RedirectResponse {
        abort_unless($request->user()->can('consultations.create'), 403);

        $orientation = $action->execute($hospitalStay, $request->user());

        return redirect("/medicine/orientations/{$orientation->uuid}/dossier")
            ->with('status', 'Visite de service ouverte.');
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

    public function printDiet(HospitalStay $hospitalStay): Response
    {
        abort_if($hospitalStay->status === HospitalStayStatus::Cancelled, 404);

        return Inertia::render('Hospitalization/DietSheetPrint', [
            'stay' => $this->stay($hospitalStay),
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
            'admitted_at' => $stay->admitted_at,
            'admitted_by' => $stay->admittedBy?->name,
            'discharged_at' => $stay->discharged_at,
            'discharged_by' => $stay->dischargedBy?->name,
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
     * Les visites de service du séjour, la plus récente d'abord.
     *
     * Ce sont de vraies consultations : la liste ne recopie donc rien de leur
     * contenu, elle mène à l'assistant qui le porte déjà.
     *
     * @return list<array<string, mixed>>
     */
    private function visits(HospitalStay $stay): array
    {
        return Consultation::query()
            ->where('episode_id', $stay->episode_id)
            ->whereHas('orientation', fn ($query) => $query
                ->where('destination_module', CatalogModule::Medicine->value)
                ->where('source_module', CatalogModule::Hospitalization->value))
            ->with(['orientation:id,uuid,status', 'doctor:id,name'])
            ->withCount(['diagnoses', 'prescriptions'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Consultation $visit): array => [
                'uuid' => $visit->uuid,
                'url' => "/medicine/orientations/{$visit->orientation->uuid}/dossier",
                'status' => $visit->status?->value,
                'is_open' => $visit->isEditable(),
                'doctor' => $visit->doctor?->name,
                'consulted_at' => $visit->consulted_at,
                'completed_at' => $visit->completed_at,
                'chief_complaint' => $visit->chief_complaint,
                'diagnoses_count' => $visit->diagnoses_count,
                'prescriptions_count' => $visit->prescriptions_count,
            ])
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
