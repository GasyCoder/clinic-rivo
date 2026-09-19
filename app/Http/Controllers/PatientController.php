<?php

namespace App\Http\Controllers;

use App\Actions\Patient\DeletePatientsAction;
use App\Actions\Patient\RecordPatientAntecedentAction;
use App\Actions\Patient\UpdatePatientAction;
use App\Enums\BillableItemStatus;
use App\Enums\CashSessionStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodePriority;
use App\Enums\EpisodeStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientType;
use App\Http\Requests\BulkDeletePatientsRequest;
use App\Http\Requests\DeletePatientRequest;
use App\Http\Requests\StorePatientAntecedentRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Models\AddressEntry;
use App\Models\BillableItem;
use App\Models\CashSession;
use App\Models\Patient;
use App\Models\PaymentMethod;
use App\Services\Billing\BillableCatalogDirectory;
use App\Services\Patient\PatientServiceNeeds;
use App\Support\EpisodePathwayTimeline;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patient directory and administrative record management. Creating a
 * patient remains Réception's job (§5.2.1): a patient is never created
 * outside the context of an arrival/passage.
 */
class PatientController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $type = in_array($request->query('type'), array_column(PatientType::cases(), 'value'), true)
            ? $request->query('type')
            : null;
        $emergency = in_array($request->query('emergency'), ['active', 'none'], true)
            ? $request->query('emergency')
            : null;
        // ADR-119 : où le patient a encore besoin d'aller — Médecine, Soins,
        // Pharmacie. `null` = tous, `[]` = aucun de ces services, sinon la
        // combinaison exacte.
        $need = PatientServiceNeeds::parse($request->query('need'));
        $needs = PatientServiceNeeds::current();

        // ADR-120 : l'onglet choisi reprend les états que la ligne affiche déjà
        // (`presenceState`) : besoin en cours, en attente de règlement, aucun
        // passage ouvert. Une urgence ouverte est toujours « en cours ».
        $status = in_array($request->query('status'), ['open', 'settlement', 'none'], true) ? $request->query('status') : null;
        $needIds = $needs->patientIds();
        $openEpisode = fn ($episode) => $episode->where('status', EpisodeStatus::Open->value);
        $inCare = fn ($episode) => $openEpisode($episode)
            ->where('administrative_status', '!=', EpisodeAdministrativeStatus::PendingSettlement->value);
        $emergencyOpen = fn ($episode) => $openEpisode($episode)->where('priority', EpisodePriority::Emergency->value);

        $statusScope = fn ($query, ?string $value) => match ($value) {
            'open' => $query->where(fn ($q) => $q->whereHas('episodes', $inCare)->orWhereHas('episodes', $emergencyOpen)),
            'settlement' => $query->whereHas('episodes', fn ($episode) => $openEpisode($episode)
                ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value))
                ->whereDoesntHave('episodes', $inCare)
                ->whereDoesntHave('episodes', $emergencyOpen),
            'none' => $query->whereDoesntHave('episodes', $openEpisode),
            default => $query,
        };
        $needScope = fn ($query, ?array $value) => match (true) {
            $value === null => $query,
            $value === [] => $query->whereNotIn('patients.id', $needIds),
            default => $query->whereIn('patients.id', $needs->idsMatching($value)),
        };

        // Les filtres que les compteurs de besoin respectent : chaque compteur
        // annonce ce que donnerait un clic sur sa case, les autres filtres
        // (recherche, type, urgence) inchangés.
        $filtered = fn () => Patient::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($type, fn ($query) => $query->where('patient_type', $type))
            ->when($emergency === 'active', fn ($query) => $query->whereHas('episodes', fn ($episode) => $episode
                ->where('priority', EpisodePriority::Emergency->value)
                ->where('status', EpisodeStatus::Open->value)))
            ->when($emergency === 'none', fn ($query) => $query->whereDoesntHave('episodes', fn ($episode) => $episode
                ->where('priority', EpisodePriority::Emergency->value)
                ->where('status', EpisodeStatus::Open->value)));

        $patients = $filtered()
            ->select([
                'id', 'uuid', 'patient_number', 'patient_type', 'first_name',
                'last_name', 'birth_date', 'birth_date_is_approximate',
                'declared_age', 'sex', 'phone',
            ])
            ->withCount([
                'episodes as active_emergency_episodes_count' => fn ($query) => $query
                    ->where('priority', EpisodePriority::Emergency->value)
                    ->where('status', EpisodeStatus::Open->value),
                // A patient with an OPEN episode is currently being handled
                // somewhere in the clinic — the directory's most actionable
                // signal after the emergency flag, and the reason a record is
                // usually looked up at all.
                'episodes as open_episodes_count' => fn ($query) => $query
                    ->where('status', EpisodeStatus::Open->value),
                // Le passage dont la partie clinique est finie : Médecine a
                // clôturé, il n'attend plus que la Réception. Sans cette
                // distinction, le répertoire affichait « Passage en cours »
                // à un médecin qui venait justement de conclure — deux écrans
                // qui semblaient se contredire.
                'episodes as settlement_episodes_count' => fn ($query) => $query
                    ->where('status', EpisodeStatus::Open->value)
                    ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value),
                'episodes as episodes_count' => fn ($query) => $query
                    ->where('status', '!=', EpisodeStatus::Cancelled->value),
            ])
            ->withMax(
                ['episodes as last_visit_at' => fn ($query) => $query
                    ->where('status', '!=', EpisodeStatus::Cancelled->value)],
                'started_at',
            )
            ->tap(fn ($query) => $needScope($statusScope($query, $status), $need))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $patients->getCollection()->each(function (Patient $patient) use ($needs) {
            $this->appendAdministrativePresentation($patient);
            $patient->setAttribute('needs', $needs->forPatient($patient->getKey()));

            // withMax() returns the driver's raw datetime string, which
            // differs between MySQL and SQLite; normalise it once here so
            // the frontend always parses the same shape.
            $lastVisit = $patient->getAttribute('last_visit_at');
            $patient->setAttribute(
                'last_visit_at',
                $lastVisit ? Carbon::parse($lastVisit)->toIso8601String() : null,
            );
        });

        return Inertia::render('Patients/Index', [
            'patients' => $patients,
            'search' => $search,
            'filters' => [
                'type' => $type,
                'emergency' => $emergency,
                'need' => $need === null ? null : PatientServiceNeeds::key($need),
                'status' => $status,
            ],
            // Chaque compteur est ce que donnerait un clic : les autres filtres
            // restent appliqués, le sien seul est levé.
            'needs' => ['facets' => $needs->facets($statusScope($filtered(), $status))],
            'segments' => [
                'status' => [
                    'all' => $needScope($filtered(), $need)->count(),
                    'open' => $needScope($statusScope($filtered(), 'open'), $need)->count(),
                    'settlement' => $needScope($statusScope($filtered(), 'settlement'), $need)->count(),
                    'none' => $needScope($statusScope($filtered(), 'none'), $need)->count(),
                ],
            ],
            'summary' => $this->directorySummary(),
        ]);
    }

    public function show(Request $request, Patient $patient, BillableCatalogDirectory $catalog, EpisodePathwayTimeline $timeline): Response
    {
        // Care record data (constants, allergy snapshot, acts performed) is
        // gated behind care.view, matching CareController's own capability
        // — the rest of this page already shows medical history (allergies,
        // antecedents) to anyone who can open a patient's file at all, so
        // this is the one clinical block on it that needs a narrower check.
        $canViewCareRecords = $request->user()->can('care.view');

        $patient->load([
            'addressEntry:id,uuid,label',
            'antecedents',
            'allergies',
            'episodes' => fn ($query) => $query
                ->with([
                    'orientations:id,episode_id,destination_module,status,oriented_at,accepted_at,completed_at',
                    ...($canViewCareRecords ? [
                        'careRecord',
                        'careRecord.procedures' => fn ($procedures) => $procedures
                            ->with('performer:id,name'),
                    ] : []),
                ])
                ->orderByDesc('started_at'),
        ]);

        if ($request->user()->can('patient_staff_links.view')) {
            $patient->load([
                'activeStaffLink:id,uuid,patient_id,employee_id,linked_at',
                'activeStaffLink.employee:id,uuid,employee_number,first_name,last_name,profession,active',
            ]);
        }

        if ($request->user()->can('patient_coverages.view')) {
            $patient->load([
                'activeMutualCoverage:id,uuid,patient_id,mutual_organization_id,employer_name,beneficiary_type,membership_number,effective_from',
                'activeMutualCoverage.organization:id,uuid,name,coverage_rate',
            ]);

            if ($request->user()->can('patient_coverage_documents.view')) {
                $patient->load('activeMutualCoverage.attachments');
            }
        }

        $this->appendAdministrativePresentation($patient);

        // ADR-117 : le même parcours que celui du détail du passage, servi
        // ici pour chaque passage du dossier — la frise ne recompose rien.
        $pathways = $timeline->forEpisodes($patient->episodes, $request->user());
        $patient->episodes->each(fn ($episode) => $episode->setAttribute('pathway', $pathways[$episode->getKey()] ?? []));

        $account = null;
        $paymentMethods = [];
        $openCashSessions = [];
        $billingCatalog = [];

        if ($request->user()->can('billing.view')) {
            $canViewPayments = $request->user()->can('payments.view');
            $patient->load([
                'invoices' => fn ($query) => $query->latest(),
                'invoices.episode:id,uuid,episode_number',
                'invoices.lines.billableItem:id,source_module',
                ...($canViewPayments ? [
                    'invoices.payments' => fn ($query) => $query
                        ->latest('paid_at'),
                    'invoices.payments.method:id,name',
                    'invoices.payments.receipt:id,uuid,payment_id,receipt_number',
                    'invoices.payments.cashier:id,name',
                    'invoices.payments.canceller:id,name',
                    'invoices.payments.cashSession:id,uuid',
                ] : []),
            ]);

            $activeInvoices = $patient->invoices->where('status', '!=', InvoiceStatus::Cancelled);
            $billableItems = BillableItem::query()
                ->whereIn('episode_id', $patient->episodes->pluck('id'))
                ->with('episode:id,uuid,episode_number')
                ->latest()
                ->get();
            $pendingItems = $billableItems->where('status', BillableItemStatus::Pending);

            $account = [
                'total_amount' => Money::fromMinor($activeInvoices->sum(fn ($invoice) => Money::toMinor($invoice->total_amount))),
                'paid_amount' => Money::fromMinor($activeInvoices->sum(fn ($invoice) => Money::toMinor($invoice->paid_amount))),
                'balance_amount' => Money::fromMinor($activeInvoices->sum(fn ($invoice) => Money::toMinor($invoice->balance_amount))),
                'unbilled_amount' => Money::fromMinor($pendingItems->sum(fn ($item) => Money::toMinor(
                    $item->patient_amount ?? $item->total_amount,
                ))),
                'billable_items' => $billableItems->map(fn ($item) => [
                    'uuid' => $item->uuid,
                    'source_module' => $item->source_module,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_amount' => $item->total_amount,
                    'gross_amount' => $item->gross_amount,
                    'coverage_amount' => $item->coverage_amount,
                    'staff_coverage_policy' => $item->staff_coverage_policy?->value,
                    'staff_covered_amount' => $item->staff_covered_amount,
                    'staff_block_credit_used' => $item->staff_block_credit_used,
                    'patient_amount' => $item->patient_amount,
                    'coverage_rate' => $item->coverage_rate,
                    'mutual_organization_name' => $item->mutual_organization_name,
                    'currency' => $item->currency,
                    'payment_required_before_fulfillment' => $item->payment_required_before_fulfillment,
                    'status' => $item->status->value,
                    'created_at' => $item->created_at,
                    'cancelled_at' => $item->cancelled_at,
                    'cancellation_reason' => $item->cancellation_reason,
                    'episode' => [
                        'uuid' => $item->episode->uuid,
                        'episode_number' => $item->episode->episode_number,
                    ],
                ])->values(),
                'invoices' => $patient->invoices->map(fn ($invoice) => [
                    'uuid' => $invoice->uuid,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status->value,
                    'currency' => $invoice->currency,
                    'financial_mode' => $invoice->financial_mode?->value,
                    'subtotal_amount' => $invoice->subtotal_amount,
                    'discount_amount' => $invoice->discount_amount,
                    'coverage_amount' => $invoice->coverage_amount,
                    'staff_covered_amount' => $invoice->staff_covered_amount,
                    'staff_block_credit_used' => $invoice->staff_block_credit_used,
                    'coverage_rate' => $invoice->coverage_rate,
                    'mutual_organization_name' => $invoice->mutual_organization_name,
                    'total_amount' => $invoice->total_amount,
                    'paid_amount' => $invoice->paid_amount,
                    'balance_amount' => $invoice->balance_amount,
                    'created_at' => $invoice->created_at,
                    'validated_at' => $invoice->validated_at,
                    'episode' => [
                        'uuid' => $invoice->episode->uuid,
                        'episode_number' => $invoice->episode->episode_number,
                    ],
                    'lines' => $invoice->lines->map(fn ($line) => [
                        'id' => $line->id,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'line_total' => $line->line_total,
                        'gross_line_total' => $line->gross_line_total,
                        'coverage_rate' => $line->coverage_rate,
                        'coverage_amount' => $line->coverage_amount,
                        'staff_coverage_policy' => $line->staff_coverage_policy?->value,
                        'staff_covered_amount' => $line->staff_covered_amount,
                        'staff_block_credit_used' => $line->staff_block_credit_used,
                        'source_module' => $line->billableItem?->source_module ?? 'RECEPTION',
                        'status' => $line->status,
                    ])->values(),
                    'payments' => $canViewPayments
                        ? $invoice->payments->map(fn ($payment) => [
                            'uuid' => $payment->uuid,
                            'payment_number' => $payment->payment_number,
                            'amount' => $payment->amount,
                            'currency' => $payment->currency,
                            'reference' => $payment->reference,
                            'status' => $payment->status->value,
                            'paid_at' => $payment->paid_at,
                            'cancelled_at' => $payment->cancelled_at,
                            'cancellation_reason' => $payment->cancellation_reason,
                            'method' => $payment->method->name,
                            'cashier' => $payment->cashier->name,
                            'canceller' => $payment->canceller?->name,
                            'cash_session_uuid' => $payment->cashSession->uuid,
                            'receipt' => $payment->receipt ? [
                                'uuid' => $payment->receipt->uuid,
                                'receipt_number' => $payment->receipt->receipt_number,
                            ] : null,
                        ])->values()
                        : [],
                ])->values(),
            ];
        }

        if ($request->user()->can('payments.create')) {
            $paymentMethods = PaymentMethod::query()
                ->where('active', true)
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'category', 'affects_cash_balance', 'requires_reference'])
                ->map(fn (PaymentMethod $method) => [
                    'id' => $method->id,
                    'code' => $method->code,
                    'name' => $method->name,
                    'category' => $method->category->value,
                    'category_label' => $method->category->label(),
                    'category_icon' => $method->category->icon(),
                    'category_position' => $method->category->position(),
                    'affects_cash_balance' => $method->affects_cash_balance,
                    'requires_reference' => $method->requires_reference,
                ]);
        }

        if ($request->user()->can('payments.create') || $request->user()->can('payments.cancel')) {
            $openCashSessions = CashSession::query()
                ->where('status', CashSessionStatus::Open->value)
                ->where('opened_by', $request->user()->id)
                ->whereNotNull('active_key')
                ->with('register:id,uuid,name')
                ->get(['uuid', 'session_number', 'opened_at', 'cash_register_id'])
                ->map(fn (CashSession $s) => [
                    'uuid' => $s->uuid,
                    'session_number' => $s->session_number,
                    'opened_at' => $s->opened_at,
                    'register_uuid' => $s->register?->uuid,
                    'register_name' => $s->register?->name,
                ]);
        }

        if ($request->user()->can('billing.create')) {
            // The account screen creates a financial document directly. In
            // contrast with Reception routing, an unpriced service cannot be
            // offered here because there is no clinical-plan fallback.
            $billingEpisode = $patient->episodes
                ->first(fn ($episode) => $episode->status !== EpisodeStatus::Cancelled);

            if ($billingEpisode?->financial_mode !== null) {
                $billingCatalog = $catalog->services($billingEpisode)
                    ->where('tariff_available', true)
                    ->values();
            }
        }

        return Inertia::render('Patients/Show', [
            'patient' => $patient,
            'account' => $account,
            'paymentMethods' => $paymentMethods,
            'openCashSessions' => $openCashSessions,
            'billingCatalog' => $billingCatalog,
        ]);
    }

    public function edit(Request $request, Patient $patient): Response
    {
        abort_if(
            $patient->patient_type === PatientType::Staff,
            403,
            'Les informations d’un patient Personnel se modifient depuis son dossier RH.',
        );

        $patient->load('addressEntry:id,uuid,label');

        $canViewCoverage = $request->user()->can('patient_coverages.view');
        $canViewCoverageDocuments = $canViewCoverage
            && $request->user()->can('patients.view')
            && $request->user()->can('patient_coverage_documents.view');

        if ($patient->patient_type === PatientType::Mutual && $canViewCoverage) {
            $patient->load([
                'activeMutualCoverage:id,uuid,patient_id,mutual_organization_id,employer_name,beneficiary_type,membership_number,effective_from,effective_until',
                'activeMutualCoverage.organization:id,uuid,name,coverage_rate',
            ]);

            if ($canViewCoverageDocuments) {
                $patient->load([
                    'activeMutualCoverage.attachments' => fn ($query) => $query
                        ->oldest('created_at'),
                ]);
            }
        }

        $coverage = $patient->relationLoaded('activeMutualCoverage')
            ? $patient->activeMutualCoverage
            : null;

        return Inertia::render('Patients/Edit', [
            'patient' => [
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
                'civility' => $patient->civility?->value,
                'identity_document_type' => $patient->identity_document_type?->value,
                'identity_document_number' => $patient->identity_document_number,
                'marital_status' => $patient->marital_status?->value,
                'children_count' => $patient->children_count,
                'profession' => $patient->profession,
                'phone' => $patient->phone,
                'email' => $patient->email,
                'address_entry_uuid' => $patient->addressEntry?->uuid,
                'address' => $patient->addressEntry?->label ?? $patient->address,
                'active_mutual_coverage' => $coverage ? [
                    'uuid' => $coverage->uuid,
                    'organization' => $coverage->organization ? [
                        'uuid' => $coverage->organization->uuid,
                        'name' => $coverage->organization->name,
                    ] : null,
                    'employer_name' => $coverage->employer_name,
                    'beneficiary_type' => $coverage->beneficiary_type?->value,
                    'membership_number' => $coverage->membership_number,
                    'effective_from' => $coverage->effective_from?->toIso8601String(),
                    ...($canViewCoverageDocuments ? [
                        'attachments_count' => $coverage->attachments->count(),
                        'attachments' => $coverage->attachments->map(fn ($attachment) => [
                            'uuid' => $attachment->uuid,
                            'original_name' => $attachment->original_name,
                            'mime_type' => $attachment->mime_type,
                            'size' => $attachment->size,
                            'is_image' => $attachment->is_image,
                            'url' => route('reception.mutual-coverages.attachments.show', [
                                'coverage' => $coverage,
                                'attachment' => $attachment,
                            ], false),
                        ])->values(),
                    ] : []),
                ] : null,
            ],
            'addressEntries' => $request->user()->can('address_entries.view')
                ? AddressEntry::query()
                    ->where('active', true)
                    ->orderBy('label')
                    ->limit(250)
                    ->get(['uuid', 'label'])
                : [],
            'capabilities' => [
                'can_view_coverage' => $canViewCoverage,
                'can_view_coverage_documents' => $canViewCoverageDocuments,
                'can_add_coverage_documents' => $canViewCoverage
                    && $request->user()->can('patient_coverage_documents.create'),
                'can_create_address_entry' => $request->user()->can('address_entries.create'),
            ],
        ]);
    }

    public function update(
        UpdatePatientRequest $request,
        Patient $patient,
        UpdatePatientAction $action,
    ): RedirectResponse {
        $action->execute($patient, $request->validated(), $request->user());

        // A fixed, whitelisted flag only — never a raw URL — so this can
        // never become an open redirect. Lets Réception correct a patient's
        // record mid-arrival and land back on its own journey instead of the
        // dossier page.
        if ($request->query('return_to') === 'reception') {
            return redirect()->route('reception.patients.create')
                ->with('status', "Dossier {$patient->patient_number} mis à jour.");
        }

        return redirect()->route('patients.show', $patient)
            ->with('status', "Dossier {$patient->patient_number} mis à jour.");
    }

    /**
     * Antecedents are a permanent record of the Patient, not of a single
     * Consultation (§19's inter-site transfer payload carries them at the
     * patient level) — this is the one generic endpoint every caller with
     * patients.medical_history.manage uses, Médecine included, rather than
     * a module-specific duplicate.
     */
    public function storeAntecedent(
        StorePatientAntecedentRequest $request,
        Patient $patient,
        RecordPatientAntecedentAction $action,
    ): RedirectResponse {
        $antecedent = $action->execute(
            $patient,
            $request->validated('description'),
            $request->antecedentType(),
        );

        return back()->with(
            'status',
            "{$antecedent->type->label()} ajouté au dossier patient.",
        );
    }

    public function destroy(
        DeletePatientRequest $request,
        Patient $patient,
        DeletePatientsAction $action,
    ): RedirectResponse {
        $action->execute([$patient], $request->validated('reason'));

        return back()
            ->with('status', "Dossier {$patient->patient_number} archivé.");
    }

    public function bulkDestroy(
        BulkDeletePatientsRequest $request,
        DeletePatientsAction $action,
    ): RedirectResponse {
        $validated = $request->validated();
        $patients = Patient::query()->whereIn('uuid', $validated['patient_uuids'])->get();
        $deleted = $action->execute($patients, $validated['reason']);

        return back()
            ->with('status', "{$deleted} dossier(s) patient archivé(s).");
    }

    private function appendAdministrativePresentation(Patient $patient): Patient
    {
        $patient->setAttribute('age', $patient->birth_date?->age ?? $patient->declared_age);
        $patient->setAttribute('patient_type_label', $patient->patient_type->label());

        return $patient;
    }

    /**
     * Site-wide counters shown above the directory. They are deliberately
     * unfiltered: they describe the whole record base so the operator can
     * read the current situation at a glance, then use them as shortcuts
     * into the very filters the list already supports.
     *
     * @return array<string, int>
     */
    private function directorySummary(): array
    {
        $emergency = fn ($episode) => $episode
            ->where('priority', EpisodePriority::Emergency->value)
            ->where('status', EpisodeStatus::Open->value);

        return [
            'total' => Patient::query()->count(),
            'emergency' => Patient::query()->whereHas('episodes', $emergency)->count(),
            'in_progress' => Patient::query()
                ->whereHas('episodes', fn ($episode) => $episode->where('status', EpisodeStatus::Open->value))
                ->count(),
            'created_this_month' => Patient::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
        ];
    }
}
