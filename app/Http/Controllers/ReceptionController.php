<?php

namespace App\Http\Controllers;

use App\Actions\Reception\RegisterArrivalAction;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\EpisodePriority;
use App\Enums\PatientType;
use App\Enums\ReceptionPatientStep;
use App\Exceptions\DuplicatePatientException;
use App\Http\Requests\StoreArrivalRequest;
use App\Models\AddressEntry;
use App\Models\Episode;
use App\Models\MutualOrganization;
use App\Models\PartnerOrganization;
use App\Models\Patient;
use App\Models\VisitorVisit;
use App\Services\Reception\ReceptionEstimateService;
use App\Services\Reception\ReceptionFinancialPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reception entry point for patient arrivals and the recent passage board. */
class ReceptionController extends Controller
{
    public function __construct(
        private readonly ReceptionEstimateService $estimates,
        private readonly ReceptionFinancialPreviewService $financialPreviews,
    ) {}

    public function index(Request $request): Response
    {
        $recentEpisodes = $request->user()->can('episodes.view')
            ? Episode::query()
                ->with('patient:id,uuid,patient_number,first_name,last_name,deleted_at')
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

    public function resumeJourney(Request $request, Episode $episode): Response|RedirectResponse
    {
        $episode->load([
            'patient',
            'receptionJourneyDraft',
            'mutualCoverage',
            'staffCoverage.employee',
            'partnerCoverage',
        ]);

        if ($episode->service_plan_finalized_at || $episode->priority === EpisodePriority::Emergency) {
            return redirect()->route('patients.show', $episode->patient);
        }

        if (! $episode->receptionJourneyDraft) {
            return redirect()->route('reception.passages.services.show', $episode);
        }

        return $this->renderPatientReception($request, episode: $episode);
    }

    public function searchPatients(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('episodes.create'), 403);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);
        $search = trim($validated['q']);

        $matches = Patient::query()
            ->where(function ($query) use ($search) {
                $query->where('patient_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('identity_document_number', 'like', "%{$search}%");
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(10)
            ->get()
            ->map(fn (Patient $patient) => $this->patientSearchPayload($patient));

        return response()->json(['data' => $matches]);
    }

    private function renderPatientReception(
        Request $request,
        ?ReceptionPatientStep $step = null,
        ?Episode $episode = null,
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
            // administrative_status already tracks exactly what these two
            // tabs mean (has Réception finalized this passage's routing
            // yet?) and flips PENDING_ORIENTATION -> ORIENTED uniformly for
            // every ADR-030 path — MEDICINE_DIRECT, CARE_THEN_MEDICINE, and
            // CARE_ONLY alike (see PlanEpisodeRoutingAction). A prior
            // version of this filter re-derived the same thing from raw
            // Care/Medicine orientation rows, tied to the pre-ADR-030 model
            // where Médecine was always the destination: it missed
            // CARE_ONLY passages that finish entirely inside Soins, which
            // matched neither bucket once their Care orientation completed.
            ->when($recentFilter === 'pending', fn ($query) => $query
                ->where('administrative_status', EpisodeAdministrativeStatus::PendingOrientation->value))
            ->when($recentFilter === 'oriented', fn ($query) => $query
                ->where('administrative_status', '!=', EpisodeAdministrativeStatus::PendingOrientation->value))
            ->latest('started_at')
            ->limit(20)
            ->get([
                'id', 'uuid', 'patient_id', 'episode_number', 'status', 'priority',
                'administrative_status', 'designation_deferred',
                'service_plan_finalized_at', 'started_at',
            ]);

        $draft = $episode?->receptionJourneyDraft;
        $draftLines = $draft?->catalog_lines ?? [];
        $financialPreview = $episode?->financial_mode !== null && $draftLines !== []
            ? $this->financialPreviews->preview($episode, $draftLines)
            : null;

        return Inertia::render('Reception/Create', [
            'step' => $step?->value,
            'search' => $search,
            'matches' => $matches,
            'recentEpisodes' => $recentEpisodes,
            'recentEpisodeFilter' => $recentFilter,
            'addressEntries' => $request->user()->can('address_entries.view')
                ? AddressEntry::query()->where('active', true)->orderBy('label')->limit(250)->get(['uuid', 'label'])
                : [],
            'mutualOrganizations' => $request->user()->can('mutual_organizations.view')
                ? MutualOrganization::query()->where('active', true)->orderBy('name')->get(['uuid', 'name', 'coverage_rate'])
                : [],
            'partnerOrganizations' => $request->user()->can('partner_organizations.view')
                ? PartnerOrganization::query()->where('active', true)->orderBy('name')->get(['uuid', 'name'])
                : [],
            'estimateCatalog' => $this->estimates->catalog(),
            'resumeEpisode' => $episode ? [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'priority' => $episode->priority->value,
                'financial_mode' => $episode->financial_mode?->value,
                'patient' => $this->patientSearchPayload($episode->patient),
                'mutual_coverage' => $episode->mutualCoverage ? [
                    'mutual_organization_uuid' => $episode->mutualCoverage->organization_uuid_snapshot,
                    'employer_name' => $episode->mutualCoverage->employer_name,
                    'beneficiary_type' => $episode->mutualCoverage->beneficiary_type->value,
                    'membership_number' => $episode->mutualCoverage->membership_number,
                ] : null,
                'staff_coverage' => $episode->staffCoverage?->employee ? [
                    'employee' => [
                        'uuid' => $episode->staffCoverage->employee->uuid,
                        'employee_number' => $episode->staffCoverage->employee->employee_number,
                        'first_name' => $episode->staffCoverage->employee->first_name,
                        'last_name' => $episode->staffCoverage->employee->last_name,
                        'profession' => $episode->staffCoverage->employee->profession,
                        'eligible' => $episode->staffCoverage->employee->active,
                    ],
                ] : null,
                'partner_coverage' => $episode->partnerCoverage ? [
                    'partner_organization_uuid' => $episode->partnerCoverage->organization_uuid_snapshot,
                ] : null,
            ] : null,
            'receptionDraft' => $draft ? [
                'catalog_lines' => $draftLines,
                'designation_deferred' => $draft->designation_deferred,
            ] : null,
            'financialPreview' => $financialPreview,
            'capabilities' => [
                'can_create_patient' => $request->user()->can('patients.create'),
                'can_update_patient' => $request->user()->can('patients.update'),
                'can_use_mutual' => $request->user()->can('mutual_organizations.view'),
                'can_use_partner' => $request->user()->can('partner_organizations.view'),
                'can_create_partner' => $request->user()->can('partner_organizations.create'),
                'can_use_staff' => $request->user()->can('employees.patient_lookup'),
                'can_link_staff' => $request->user()->can('patient_staff_links.create'),
                'can_open_pharmacy_counter_sale' => $request->user()->can('pharmacy.counter_sales.create'),
                'can_manage_catalog' => $request->user()->can('catalog.items.view'),
                'can_mark_emergency' => $episode !== null
                    && $episode->priority !== EpisodePriority::Emergency
                    && $request->user()->can('episodes.mark_emergency'),
            ],
        ]);
    }

    public function storePatient(
        StoreArrivalRequest $request,
        RegisterArrivalAction $action,
    ): RedirectResponse|JsonResponse {
        $jsonWorkflow = $request->expectsJson();

        try {
            $patientData = $request->safe()->only([
                'patient_type', 'first_name', 'last_name', 'birth_date', 'age',
                'sex', 'civility', 'identity_document_type', 'identity_document_number',
                'marital_status', 'children_count', 'profession', 'phone', 'email',
                'address_entry_uuid', 'new_address_label',
            ]);

            // ADR-034: the contact reachable for this patient can differ
            // from one passage to the next, so it belongs to the episode
            // being opened here — for a new patient and a returning one
            // alike — not to the permanent patient record.
            $episodeData = $request->safe()->only([
                'emergency_contact_name', 'emergency_contact_phone',
                'emergency_contact_email', 'emergency_contact_relationship',
            ]);

            $receptionDraft = $request->validated('reception_draft');

            if ($receptionDraft && ! $receptionDraft['designation_deferred']) {
                $estimated = $this->estimates->estimate($receptionDraft['catalog_lines']);
                $receptionDraft['catalog_lines'] = collect($estimated['lines'])
                    ->map(fn (array $line) => [
                        'catalog_item_uuid' => $line['catalog_item_uuid'],
                        'quantity' => $line['quantity'],
                    ])
                    ->values()
                    ->all();
            }

            $episode = $action->execute(
                existingPatientUuid: $request->validated('patient_uuid'),
                newPatientData: $request->filled('patient_uuid') ? null : $patientData,
                confirmDuplicate: $request->boolean('confirm_duplicate'),
                priority: EpisodePriority::Normal,
                actor: $request->user(),
                employeeUuid: $jsonWorkflow ? null : $request->validated('employee_uuid'),
                mutualData: ! $jsonWorkflow && $request->input('patient_type') === PatientType::Mutual->value ? [
                    'organization_name' => $request->validated('mutual_organization_name'),
                    'employer_name' => $request->validated('mutual_employer_name'),
                    'beneficiary_type' => $request->validated('mutual_beneficiary_type'),
                    'membership_number' => $request->validated('mutual_membership_number'),
                ] : null,
                mutualAttachments: $jsonWorkflow ? [] : $request->file('mutual_attachments', []),
                episodeData: $episodeData,
                financialMode: $jsonWorkflow
                    ? null
                    : ($request->filled('patient_uuid')
                        ? EpisodeFinancialMode::Self
                        : match ($request->input('patient_type')) {
                            PatientType::Mutual->value => EpisodeFinancialMode::Mutual,
                            PatientType::Staff->value => EpisodeFinancialMode::Staff,
                            default => EpisodeFinancialMode::Self,
                        }),
                receptionDraft: $receptionDraft,
            );
        } catch (DuplicatePatientException $exception) {
            $duplicates = $exception->matches->map(fn (Patient $patient) => [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'birth_date' => $patient->birth_date?->toDateString(),
                'declared_age' => $patient->declared_age,
            ])->all();

            if ($jsonWorkflow) {
                return response()->json([
                    'message' => 'Un dossier patient similaire existe déjà.',
                    'duplicates' => $duplicates,
                ], 422);
            }

            return back()->withInput()->with('duplicates', $exception->matches->map(fn (Patient $patient) => [
                'uuid' => $patient->uuid,
                'patient_number' => $patient->patient_number,
                'first_name' => $patient->first_name,
                'last_name' => $patient->last_name,
                'birth_date' => $patient->birth_date?->toDateString(),
                'declared_age' => $patient->declared_age,
            ])->all());
        }

        $message = "Passage {$episode->episode_number} créé. Sélectionnez maintenant les prestations demandées.";

        if ($jsonWorkflow) {
            // patientSearchPayload also presents birth/sex/contact fields;
            // load the complete local model, then expose only its explicit
            // UUID-based projection below (never the SQL id).
            $episode->load('patient');

            return response()->json([
                'message' => $message,
                'patient' => $this->patientSearchPayload($episode->patient),
                'episode' => [
                    'uuid' => $episode->uuid,
                    'episode_number' => $episode->episode_number,
                    'priority' => $episode->priority->value,
                    'financial_mode' => $episode->financial_mode?->value,
                    'started_at' => $episode->started_at,
                ],
                'resume_url' => $episode->receptionJourneyDraft
                    ? route('reception.passages.journey.show', $episode)
                    : route('reception.passages.services.show', $episode),
            ], 201);
        }

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
}
