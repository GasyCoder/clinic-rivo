<?php

namespace App\Http\Controllers;

use App\Actions\Reception\RegisterArrivalAction;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\PatientType;
use App\Enums\ReceptionPatientStep;
use App\Exceptions\DuplicatePatientException;
use App\Http\Requests\StoreArrivalRequest;
use App\Models\AddressEntry;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\MutualOrganization;
use App\Models\Patient;
use App\Models\VisitorVisit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reception entry point for patient arrivals and the recent passage board. */
class ReceptionController extends Controller
{
    public function index(Request $request): Response
    {
        $recentEpisodes = $request->user()->can('episodes.view')
            ? Episode::query()
                ->with('patient:id,uuid,patient_number,first_name,last_name')
                ->latest('started_at')
                ->limit(8)
                ->get([
                    'id', 'uuid', 'patient_id', 'episode_number', 'status', 'priority',
                    'administrative_status', 'service_plan_finalized_at', 'started_at',
                ])
            : collect();

        $presentVisitors = $request->user()->can('visitors.view')
            ? VisitorVisit::query()
                ->with('patient:id,uuid,patient_number,first_name,last_name')
                ->whereNull('checked_out_at')
                ->latest('checked_in_at')
                ->limit(8)
                ->get()
            : collect();

        return Inertia::render('Reception/Index', [
            'recentEpisodes' => $recentEpisodes,
            'presentVisitors' => $presentVisitors,
        ]);
    }

    public function patients(Request $request): Response|RedirectResponse
    {
        $search = trim((string) $request->query('q', ''));

        if ($search !== '') {
            return redirect()->route('reception.patients.step', [
                'step' => ReceptionPatientStep::Identity->value,
                'q' => $search,
            ]);
        }

        return $this->renderPatientReception($request);
    }

    public function patientStep(Request $request, string $step): Response
    {
        return $this->renderPatientReception($request, ReceptionPatientStep::from($step));
    }

    private function renderPatientReception(
        Request $request,
        ?ReceptionPatientStep $step = null,
    ): Response {
        $search = trim((string) $request->query('q', ''));
        $recentFilter = in_array($request->query('filter'), [
            'normal', 'emergency', 'pending', 'oriented',
        ], true) ? $request->query('filter') : 'all';

        $matches = $search !== ''
            ? Patient::query()
                ->where(function ($query) use ($search) {
                    $query->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                })
                ->limit(10)
                ->get()
                ->map(fn (Patient $patient) => $this->patientSearchPayload($patient))
            : collect();

        $recentEpisodes = Episode::query()
            ->with([
                'patient:id,uuid,patient_number,patient_type,first_name,last_name',
                'orientations:id,episode_id,destination_module,status,oriented_at,accepted_at,completed_at',
            ])
            ->when($recentFilter === 'normal', fn ($query) => $query
                ->where('priority', EpisodePriority::Normal->value))
            ->when($recentFilter === 'emergency', fn ($query) => $query
                ->where('priority', EpisodePriority::Emergency->value))
            ->when($recentFilter === 'pending', fn ($query) => $query
                ->where(function ($pending) {
                    $pending->where(function ($unplanned) {
                        $unplanned->whereNull('service_plan_finalized_at')
                            // An emergency is clinically oriented as soon as
                            // the passage is created, even while the family
                            // still completes its designation afterwards.
                            ->whereDoesntHave('orientations', fn ($orientation) => $orientation
                                ->where('destination_module', CatalogModule::Medicine->value));
                    })
                        ->orWhere(function ($carePath) {
                            $carePath->whereHas('orientations', fn ($orientation) => $orientation
                                ->where('destination_module', CatalogModule::Care->value)
                                ->whereIn('status', [
                                    EpisodeOrientationStatus::Pending->value,
                                    EpisodeOrientationStatus::InProgress->value,
                                ]))
                                ->whereDoesntHave('orientations', fn ($orientation) => $orientation
                                    ->where('destination_module', CatalogModule::Medicine->value)
                                    ->whereIn('status', [
                                        EpisodeOrientationStatus::Pending->value,
                                        EpisodeOrientationStatus::InProgress->value,
                                        EpisodeOrientationStatus::Completed->value,
                                    ]));
                        });
                }))
            ->when($recentFilter === 'oriented', fn ($query) => $query
                ->whereHas('orientations', fn ($orientation) => $orientation
                    ->where('destination_module', CatalogModule::Medicine->value)
                    ->whereIn('status', [
                        EpisodeOrientationStatus::Pending->value,
                        EpisodeOrientationStatus::InProgress->value,
                        EpisodeOrientationStatus::Completed->value,
                    ])))
            ->latest('started_at')
            ->limit(20)
            ->get([
                'id', 'uuid', 'patient_id', 'episode_number', 'status', 'priority',
                'administrative_status', 'designation_deferred',
                'service_plan_finalized_at', 'started_at',
            ]);

        return Inertia::render('Reception/Create', [
            'step' => $step?->value,
            'search' => $search,
            'matches' => $matches,
            'recentEpisodes' => $recentEpisodes,
            'recentEpisodeFilter' => $recentFilter,
            'addressEntries' => $request->user()->can('address_entries.view')
                ? AddressEntry::query()->where('active', true)->orderBy('label')->limit(250)->get(['uuid', 'label'])
                : [],
            'staffEmployees' => $request->user()->can('employees.patient_lookup')
                ? Employee::query()
                    ->with([
                        'addressEntry:id,uuid,label',
                        'activePatientLink.patient:id,uuid,patient_number,first_name,last_name',
                    ])
                    ->where('active', true)
                    ->orderBy('last_name')
                    ->orderBy('first_name')
                    ->limit(100)
                    ->get()
                    ->map(fn (Employee $employee) => $this->employeeLookupPayload($employee))
                : [],
            'mutualOrganizations' => $request->user()->can('mutual_organizations.view')
                ? MutualOrganization::query()->where('active', true)->orderBy('name')->get(['uuid', 'name'])
                : [],
        ]);
    }

    public function storePatient(
        StoreArrivalRequest $request,
        RegisterArrivalAction $action,
    ): RedirectResponse {
        try {
            $patientData = $request->safe()->only([
                'patient_type', 'first_name', 'last_name', 'birth_date', 'age',
                'sex', 'civility', 'identity_document_type', 'identity_document_number',
                'marital_status', 'children_count', 'profession', 'phone', 'email',
                'address_entry_uuid', 'new_address_label',
                'emergency_contact_name', 'emergency_contact_phone',
                'emergency_contact_email', 'emergency_contact_relationship',
            ]);

            $episode = $action->execute(
                existingPatientUuid: $request->validated('patient_uuid'),
                newPatientData: $request->filled('patient_uuid') ? null : $patientData,
                confirmDuplicate: $request->boolean('confirm_duplicate'),
                priority: $request->boolean('is_emergency')
                    ? EpisodePriority::Emergency
                    : EpisodePriority::Normal,
                actor: $request->user(),
                employeeUuid: $request->validated('employee_uuid'),
                mutualData: $request->input('patient_type') === PatientType::Mutual->value ? [
                    'organization_name' => $request->validated('mutual_organization_name'),
                    'employer_name' => $request->validated('mutual_employer_name'),
                    'beneficiary_type' => $request->validated('mutual_beneficiary_type'),
                    'membership_number' => $request->validated('mutual_membership_number'),
                ] : null,
                mutualAttachments: $request->file('mutual_attachments', []),
            );
        } catch (DuplicatePatientException $exception) {
            return back()->withInput()->with('duplicates', $exception->matches->map(fn (Patient $patient) => [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'birth_date' => $patient->birth_date?->toDateString(),
                'declared_age' => $patient->declared_age,
            ])->all());
        }

        $message = $episode->priority === EpisodePriority::Emergency
            ? "Passage urgence {$episode->episode_number} créé ; Soins et Médecine sont déjà alertés."
            : "Passage {$episode->episode_number} créé. Sélectionnez maintenant les prestations demandées.";

        return redirect()->route('reception.passages.services.show', $episode)
            ->with('status', $message);
    }

    /** @return array<string, mixed> */
    private function patientSearchPayload(Patient $patient): array
    {
        return [
            'uuid' => $patient->uuid,
            'patient_number' => $patient->patient_number,
            'patient_type' => $patient->patient_type->value,
            'first_name' => $patient->first_name,
            'last_name' => $patient->last_name,
            'birth_date' => $patient->birth_date?->toDateString(),
            'birth_date_is_approximate' => $patient->birth_date_is_approximate,
            'declared_age' => $patient->declared_age,
            'age' => $patient->birth_date?->age ?? $patient->declared_age,
            'sex' => $patient->sex->value,
            'phone' => $patient->phone,
            'email' => $patient->email,
        ];
    }

    /** @return array<string, mixed> */
    private function employeeLookupPayload(Employee $employee): array
    {
        return [
            'uuid' => $employee->uuid,
            'employee_number' => $employee->employee_number,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'birth_date' => $employee->birth_date?->toDateString(),
            'sex' => $employee->sex->value,
            'profession' => $employee->profession,
            'phone' => $employee->phone,
            'email' => $employee->email,
            'address' => $employee->addressEntry?->label ?? $employee->address,
            'linked_patient' => $employee->activePatientLink?->patient ? [
                'uuid' => $employee->activePatientLink->patient->uuid,
                'patient_number' => $employee->activePatientLink->patient->patient_number,
            ] : null,
        ];
    }
}
