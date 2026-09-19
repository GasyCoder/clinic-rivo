<?php

namespace App\Http\Controllers;

use App\Actions\Hospitalization\DischargeHospitalStayAction;
use App\Actions\Hospitalization\RecordHospitalDietEntryAction;
use App\Actions\Hospitalization\UpdateHospitalizationRequestAction;
use App\Enums\EpisodeStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalDischargeType;
use App\Http\Requests\Hospitalization\DischargeHospitalStayRequest;
use App\Http\Requests\Hospitalization\StoreHospitalDietEntryRequest;
use App\Http\Requests\Hospitalization\UpdateHospitalizationRequestRequest;
use App\Http\Requests\Hospitalization\UpdateHospitalStayRequest;
use App\Models\HospitalDietEntry;
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
        $filter = in_array($request->query('filter'), ['active', 'discharged'], true)
            ? $request->query('filter')
            : 'active';

        $base = HospitalStay::query()->where('status', '!=', HospitalStayStatus::Cancelled->value);

        $counts = [
            'active' => (clone $base)->where('status', HospitalStayStatus::Active->value)->count(),
            'discharged' => (clone $base)->where('status', HospitalStayStatus::Discharged->value)->count(),
        ];

        $stays = $base
            ->where('status', $filter === 'active' ? HospitalStayStatus::Active->value : HospitalStayStatus::Discharged->value)
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
            ->orderByDesc($filter === 'active' ? 'admitted_at' : 'discharged_at')
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
            'filter' => $filter,
            'search' => $search,
        ]);
    }

    public function show(Request $request, HospitalStay $hospitalStay): Response
    {
        abort_if($hospitalStay->status === HospitalStayStatus::Cancelled, 404);

        $user = $request->user();

        return Inertia::render('Hospitalization/Show', [
            'stay' => $this->stay($hospitalStay),
            'dischargeTypes' => collect(MedicalDischargeType::cases())
                ->map(fn (MedicalDischargeType $type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
            'transferDestinations' => $this->transferDestinations(),
            'capabilities' => [
                'can_record_diet' => $user->can('hospital_diet.record')
                    && $hospitalStay->episode->status === EpisodeStatus::Open,
                'can_add_diet' => $user->can('hospital_diet.record') && $hospitalStay->isActive(),
                'can_update_stay' => $user->can('hospitalization.update') && $hospitalStay->isActive(),
                'can_edit_request' => $user->can('hospitalization.request') && $hospitalStay->isActive(),
                'can_discharge' => $user->can('medical_discharge.create') && $hospitalStay->isActive(),
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

    public function discharge(
        DischargeHospitalStayRequest $request,
        HospitalStay $hospitalStay,
        DischargeHospitalStayAction $action,
    ): RedirectResponse {
        $discharge = $action->execute($hospitalStay, $request->validated(), $request->user());

        // Un décès prononcé ici mène au registre, comme depuis une
        // consultation (ADR-107).
        if ($discharge->type === MedicalDischargeType::Deceased && $request->user()->can('death_records.view')) {
            return redirect()->route('deaths.index')
                ->with('status', 'Décès prononcé. Établissez l’acte de constatation.');
        }

        return redirect()->route('hospitalization.show', $hospitalStay)
            ->with('status', 'Sortie d’hospitalisation prononcée. Le passage rejoint « Sorties & règlements ».');
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

    /** Les autres sites, comme destinations de transfert (même source que Médecine). */
    private function transferDestinations(): array
    {
        return collect(config('rivo.clinics', []))
            ->filter(fn (array $site) => strtoupper((string) ($site['code'] ?? '')) !== strtoupper((string) config('rivo.site.code')))
            ->map(fn (array $site) => [
                'code' => $site['code'],
                'name' => $site['name'],
                'destination' => 'Clinique Saint Georges — '.$site['name'],
            ])
            ->values()
            ->all();
    }
}
