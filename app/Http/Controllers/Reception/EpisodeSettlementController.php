<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Reception\RecordAdministrativeExitAction;
use App\Enums\AdministrativeExitType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordAdministrativeExitRequest;
use App\Models\Episode;
use App\Models\Invoice;
use App\Services\Reception\EpisodeAccountControl;
use App\Support\Documents\PaperPatient;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réception's settlement board: the passages the doctor has finished with
 * and which are now waiting on an administrative decision (CDC §33.3).
 *
 * Read-only for the clinical side — nothing here touches a diagnosis, a
 * prescription or an orientation (§34.2 rule 1). It shows the account
 * control of §33.2 and records the exit; collecting the money itself stays
 * where it belongs, at the Caisse (ADR-012).
 */
class EpisodeSettlementController extends Controller
{
    public function __construct(private readonly EpisodeAccountControl $accounts) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewAccounts = $user->can('billing.view');

        $tab = in_array($request->query('tab'), ['pending', 'discharged'], true)
            ? $request->query('tab')
            : 'pending';
        $search = trim((string) $request->query('q', ''));

        $episodes = Episode::query()
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name,phone,deleted_at',
                'medicalDischarge:id,episode_id,type,discharged_at',
                'administrativeExitAuthor:id,name',
            ])
            ->when($tab === 'pending', fn (Builder $query) => $query
                ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value))
            ->when($tab === 'discharged', fn (Builder $query) => $query
                ->whereIn('administrative_status', [
                    EpisodeAdministrativeStatus::DischargedPaid->value,
                    EpisodeAdministrativeStatus::DischargedDebt->value,
                    EpisodeAdministrativeStatus::DischargedEscaped->value,
                    // Legacy bucket: nothing writes it, but a row carrying
                    // it must still be findable rather than vanish.
                    EpisodeAdministrativeStatus::Discharged->value,
                ]))
            ->when($search !== '', fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('episode_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn (Builder $patient) => $patient
                        ->where('patient_number', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))))
            ->orderByRaw($tab === 'pending'
                // Waiting longest first: that patient is standing at the desk.
                ? 'COALESCE(started_at, created_at) ASC'
                : 'COALESCE(administrative_exit_at, ended_at, updated_at) DESC')
            ->paginate(20)
            ->withQueryString();

        $summaries = $canViewAccounts
            ? $this->accounts->summarizeMany($episodes->pluck('id')->all())
            : [];

        return Inertia::render('Reception/Settlements/Index', [
            'tab' => $tab,
            'filters' => ['q' => $search],
            'episodes' => [
                'data' => $episodes->getCollection()->map(fn (Episode $episode) => [
                    'uuid' => $episode->uuid,
                    'episode_number' => $episode->episode_number,
                    'priority' => $episode->priority->value,
                    'status' => $episode->status->value,
                    'administrative_status' => $episode->administrative_status?->value,
                    'administrative_status_label' => $episode->administrative_status?->label(),
                    'medical_status' => $episode->medical_status?->value,
                    'medical_status_label' => $episode->medical_status?->label(),
                    'started_at' => $episode->started_at,
                    'medical_discharge' => $episode->medicalDischarge ? [
                        'type' => $episode->medicalDischarge->type->value,
                        'type_label' => $episode->medicalDischarge->type->label(),
                        'discharged_at' => $episode->medicalDischarge->discharged_at,
                    ] : null,
                    'administrative_exit' => $episode->administrative_exit_type ? [
                        'type' => $episode->administrative_exit_type->value,
                        'type_label' => $episode->administrative_exit_type->label(),
                        'exited_at' => $episode->administrative_exit_at,
                        'balance_amount' => $episode->administrative_exit_balance,
                        'reason' => $episode->administrative_exit_reason,
                        'author' => $episode->administrativeExitAuthor?->name,
                    ] : null,
                    // Amounts belong to billing.view, not to the route's own
                    // permission — same section-by-section guarding as the
                    // passage detail page (ADR-054).
                    'account' => $summaries[$episode->id] ?? null,
                    'patient' => $episode->patient ? [
                        'uuid' => $episode->patient->uuid,
                        'patient_number' => $episode->patient->patient_number,
                        'first_name' => $episode->patient->first_name,
                        'last_name' => $episode->patient->last_name,
                        'phone' => $episode->patient->phone,
                        'deleted_at' => $episode->patient->deleted_at,
                    ] : null,
                ])->values(),
                'links' => $episodes->linkCollection(),
                'meta' => [
                    'current_page' => $episodes->currentPage(),
                    'last_page' => $episodes->lastPage(),
                    'total' => $episodes->total(),
                    'from' => $episodes->firstItem(),
                    'to' => $episodes->lastItem(),
                ],
            ],
            'counts' => $this->counts($canViewAccounts),
            'exitTypes' => array_map(fn (AdministrativeExitType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'creates_debt' => $type->createsDebt(),
            ], AdministrativeExitType::cases()),
            'capabilities' => [
                'can_view_accounts' => $canViewAccounts,
                'can_record_exit' => $user->can('episodes.administrative_exit'),
                'can_authorize_debt' => $user->can('debts.authorize'),
                'can_collect' => $user->can('payments.create'),
                'can_view_cash' => $user->can('cash.view'),
                'can_view_patients' => $user->can('patients.view'),
            ],
        ]);
    }

    /**
     * Les chiffres de la file, comptés en base.
     *
     * Recalculés depuis la page affichée, ils mentiraient dès la deuxième —
     * et c'est précisément quand la file est longue qu'on les regarde.
     *
     * « Reste à payer » est la somme des soldes de factures non annulées
     * des passages encore à régler : la définition du §33.2, pas une
     * approximation. Les prestations encore `PENDING` en sont exclues —
     * elles ne sont portées sur aucune facture validée, donc le patient ne
     * les doit pas encore (ADR-090) ; l'écran les signale séparément.
     *
     * Le total n'est servi qu'à qui peut voir les comptes : `billing.view`
     * garde la donnée financière, ici comme dans le reste de l'écran.
     *
     * @return array<string, mixed>
     */
    private function counts(bool $canViewAccounts): array
    {
        $dischargedStatuses = [
            EpisodeAdministrativeStatus::DischargedPaid->value,
            EpisodeAdministrativeStatus::DischargedDebt->value,
            EpisodeAdministrativeStatus::DischargedEscaped->value,
            EpisodeAdministrativeStatus::Discharged->value,
        ];

        $pendingEpisodeIds = Episode::query()
            ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value)
            ->select('id');

        return [
            'pending' => (clone $pendingEpisodeIds)->count(),
            'discharged' => Episode::query()->whereIn('administrative_status', $dischargedStatuses)->count(),
            'with_debt' => Episode::query()
                ->where('administrative_status', EpisodeAdministrativeStatus::DischargedDebt->value)
                ->count(),
            'outstanding' => $canViewAccounts
                ? (float) Invoice::query()
                    ->whereIn('episode_id', $pendingEpisodeIds)
                    ->where('status', '!=', InvoiceStatus::Cancelled->value)
                    ->sum('balance_amount')
                : null,
        ];
    }

    /**
     * ADR-116 — la « FICHE DE SORTIE » de la clinique, imprimée une fois la
     * sortie administrative prononcée : ni avant (rien à signer), ni sans
     * elle (`episodes.settlement.view` ne suffit pas à contourner
     * l'exigence CDC §33.3 que la sortie soit réellement enregistrée).
     *
     * Elle porte les deux sorties distinctes du dossier — médicale
     * (ADR-035) et administrative (ADR-090) — plutôt que de forcer la seule
     * case « Date de sortie » du papier à choisir entre les deux : le
     * papier ignorait cette distinction, le système ne l'invente pas pour
     * autant côté serveur.
     */
    public function printExitSlip(Request $request, Episode $episode): Response
    {
        abort_unless($episode->administrative_exit_type !== null, 404);

        $episode->load([
            'patient.addressEntry:id,label',
            'medicalDischarge',
            'administrativeExitAuthor:id,name',
            'debts' => fn ($query) => $query->latest(),
        ]);

        $debt = $episode->debts->first();

        return Inertia::render('Reception/Settlements/ExitSlipPrint', [
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'started_at' => $episode->started_at,
            ],
            'patient' => PaperPatient::present($episode->patient),
            'medical_discharge' => $episode->medicalDischarge ? [
                'type' => $episode->medicalDischarge->type->value,
                'type_label' => $episode->medicalDischarge->type->label(),
                'discharged_at' => $episode->medicalDischarge->discharged_at,
            ] : null,
            'administrative_exit' => [
                'type' => $episode->administrative_exit_type->value,
                'type_label' => $episode->administrative_exit_type->label(),
                'exited_at' => $episode->administrative_exit_at,
                'balance_amount' => $episode->administrative_exit_balance,
                'author' => $episode->administrativeExitAuthor?->name,
                'debt_number' => $debt?->debt_number,
            ],
        ]);
    }

    public function store(
        RecordAdministrativeExitRequest $request,
        Episode $episode,
        RecordAdministrativeExitAction $action,
    ): RedirectResponse {
        $episode = $action->execute($episode, $request->validated(), $request->user());

        return back()->with(
            'status',
            "Passage {$episode->episode_number} — {$episode->administrative_exit_type->label()}.",
        );
    }
}
