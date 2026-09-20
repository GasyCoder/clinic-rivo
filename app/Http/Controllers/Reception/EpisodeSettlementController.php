<?php

namespace App\Http\Controllers\Reception;

use App\Actions\Reception\BulkSettlementAction;
use App\Actions\Reception\InvoicePendingPrestationsAction;
use App\Actions\Reception\RecordAdministrativeExitAction;
use App\Enums\AdministrativeExitType;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordAdministrativeExitRequest;
use App\Models\Episode;
use App\Models\Invoice;
use App\Services\Audit\Auditor;
use App\Services\Reception\EpisodeAccountControl;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Support\Documents\PaperPatient;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    /** Au-delà, une sélection n'est plus un geste ciblé : filtrez ou traitez par étapes. */
    private const BULK_LIMIT = 50;

    public function __construct(private readonly EpisodeAccountControl $accounts) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canViewAccounts = $user->can('billing.view');
        $canViewStays = $user->can('hospitalization.view');

        $tab = in_array($request->query('tab'), ['pending', 'in_care', 'discharged'], true)
            ? $request->query('tab')
            : 'pending';
        $search = trim((string) $request->query('q', ''));

        $episodes = Episode::query()
            ->with([
                'patient:id,uuid,patient_number,first_name,last_name,phone,deleted_at',
                'medicalDischarge:id,episode_id,type,discharged_at',
                'administrativeExitAuthor:id,name',
                // ADR-156 — le module Hospitalisation ne liste plus les
                // séjours terminés : ils ne doivent pas se perdre pour autant.
                // Toutes les sorties se suivent ici, et un passage qui est
                // passé par un lit le dit.
                'hospitalStays' => fn ($query) => $query
                    ->where('status', '!=', HospitalStayStatus::Cancelled->value)
                    ->latest('id')
                    ->limit(1),
            ])
            ->when($tab === 'pending', fn (Builder $query) => $query
                ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value))
            // ADR-156 — un passage médicalement sorti dont le service n'a pas
            // encore clôturé n'est pas réglable (ADR-054/084) : il n'a rien à
            // faire dans « À régler ». Mais il ne doit pas disparaître pour
            // autant — le lit est rendu, le patient s'en va, et la Réception
            // doit savoir qu'il arrive et pourquoi il n'est pas encore là.
            ->when($tab === 'in_care', fn (Builder $query) => $query
                ->where('administrative_status', EpisodeAdministrativeStatus::InCare->value)
                ->whereHas('medicalDischarge'))
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
            ->orderByRaw(in_array($tab, ['pending', 'in_care'], true)
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
                    // Le fait d'être passé par un lit est du parcours, de même
                    // nature qu'une orientation (ADR-117) : il est servi à qui
                    // voit la file. Le lien vers le séjour, lui, n'est proposé
                    // qu'à qui peut l'ouvrir — un lien qui mène à un refus vaut
                    // moins qu'une absence de lien.
                    'stay' => ($stay = $episode->hospitalStays->first()) ? [
                        'uuid' => $canViewStays ? $stay->uuid : null,
                        'service' => $stay->service,
                        'room_bed' => $stay->room_bed,
                        'admitted_at' => $stay->admitted_at,
                        'discharged_at' => $stay->discharged_at,
                        'is_active' => $stay->status === HospitalStayStatus::Active,
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
                // ADR-090 (amendement du 2026-09-20) : porter les prestations en attente sur une facture.
                'can_invoice' => $user->can('billing.create'),
                'can_authorize_debt' => $user->can('debts.authorize'),
                'can_record_escape' => $user->can('debts.record_escape'),
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
            // Sortis médicalement, pas encore clôturés par leur service.
            'in_care' => Episode::query()
                ->where('administrative_status', EpisodeAdministrativeStatus::InCare->value)
                ->whereHas('medicalDischarge')
                ->count(),
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

        return Inertia::render('Reception/Settlements/ExitSlipPrint', $this->slipPayload($episode));
    }

    /**
     * Sélection multiple — les fiches de sortie de plusieurs passages dans un
     * seul document, chacune sur sa page (même mécanique que les journaux de
     * traitement réunis, ADR-118). Un passage sans sortie prononcée n'a rien à
     * signer : il est écarté et compté, jamais imprimé vide.
     */
    public function printExitSlips(Request $request): Response
    {
        $episodes = $this->selection($request, 'uuids');
        $printable = $episodes->filter(fn (Episode $episode) => $episode->administrative_exit_type !== null)->values();

        return Inertia::render('Reception/Settlements/ExitSlipsPrint', [
            'slips' => $printable->map(fn (Episode $episode) => $this->slipPayload($episode))->all(),
            'skipped' => $episodes->count() - $printable->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function slipPayload(Episode $episode): array
    {
        $episode->loadMissing([
            'patient.addressEntry:id,label',
            'medicalDischarge',
            'administrativeExitAuthor:id,name',
            'debts' => fn ($query) => $query->latest(),
        ]);

        $debt = $episode->debts->first();

        return [
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
        ];
    }

    /**
     * Les passages cochés, relus depuis la base.
     *
     * La liste envoyée par l'écran n'est qu'un ensemble d'UUID : bornée, sans
     * doublon, et jamais une source de vérité sur l'état d'un passage. Ce qui
     * n'existe pas est ignoré ; chaque action revérifie ensuite le sien.
     *
     * @return Collection<int, Episode>
     */
    private function selection(Request $request, string $key): Collection
    {
        $uuids = $request->validate([
            $key => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            "{$key}.*" => ['required', 'uuid', 'distinct'],
        ])[$key];

        return Episode::query()
            ->with('patient:id,uuid,patient_number,first_name,last_name,phone,deleted_at')
            ->whereIn('uuid', $uuids)
            ->orderByRaw('COALESCE(started_at, created_at) ASC')
            ->get();
    }

    /**
     * Le rapport d'un lot, remis à l'écran : ce qui est passé, ce qui ne l'est
     * pas, et pourquoi. Le ton du message suit le résultat.
     *
     * @param  array{action: string, total: int, done: int, failed: array<int, mixed>}  $report
     */
    private function reported(array $report, string $doneLabel): RedirectResponse
    {
        $failed = count($report['failed']);

        return back()
            ->with('status', $failed === 0
                ? "{$report['done']} passage".($report['done'] > 1 ? 's' : '')." : {$doneLabel}."
                : "{$report['done']} sur {$report['total']} passage".($report['total'] > 1 ? 's' : '')." : {$doneLabel}. {$failed} refusé".($failed > 1 ? 's' : '').' — détail ci-dessous.')
            ->with('status_type', match (true) {
                $report['done'] === 0 => 'danger',
                $failed > 0 => 'warning',
                default => 'success',
            })
            ->with('bulk_report', $report);
    }

    /**
     * Sortie « payé comptant » de plusieurs passages à la fois (voir
     * `BulkSettlementAction`) : chaque passage est jugé par l'action qui le juge
     * seul. Dette validée et évasion ne se décident pas sur une liste.
     */
    public function bulkPaidCashExit(Request $request, BulkSettlementAction $bulk): RedirectResponse
    {
        $report = $bulk->paidCashExit($this->selection($request, 'episode_uuids'), $request->user());

        return $this->reported($report, 'sortie « payé comptant » prononcée');
    }

    /** Facturer d'un coup les prestations en attente de plusieurs passages. */
    public function bulkInvoice(Request $request, BulkSettlementAction $bulk): RedirectResponse
    {
        $report = $bulk->invoicePending($this->selection($request, 'episode_uuids'), $request->user());

        return $this->reported($report, 'prestations facturées');
    }

    /**
     * Export Excel des passages cochés — lecture seule. Les montants suivent
     * `billing.view`, comme le reste de l'écran (ADR-054) : sans ce droit, les
     * colonnes financières sont absentes, pas vides.
     */
    public function exportSelection(Request $request, ExcelWorkbook $workbook, Auditor $auditor)
    {
        $episodes = $this->selection($request, 'uuids')
            ->load(['medicalDischarge:id,episode_id,type,discharged_at', 'administrativeExitAuthor:id,name']);
        $withAmounts = $request->user()->can('billing.view');
        $accounts = $withAmounts ? $this->accounts->summarizeMany($episodes->pluck('id')->all()) : [];

        $auditor->record(
            'settlement.export',
            newValues: ['rows' => $episodes->count(), 'episodes' => $episodes->pluck('episode_number')->all()],
            module: 'reception',
        );

        $headers = ['N° patient', 'Patient', 'N° passage', 'Arrivée', 'Situation', 'Sortie médicale', 'Date sortie médicale', 'Sortie administrative', 'Date sortie administrative'];

        if ($withAmounts) {
            array_push($headers, 'Facturé', 'Payé', 'Reste à payer', 'Non facturé', 'Reste dû à la sortie');
        }

        return $workbook->download(
            'sorties-'.now()->format('Y-m-d'),
            'Sorties et règlements',
            $headers,
            $episodes->map(function (Episode $episode) use ($withAmounts, $accounts): array {
                $row = [
                    $episode->patient?->patient_number,
                    trim(($episode->patient?->last_name ?? '').' '.($episode->patient?->first_name ?? '')),
                    $episode->episode_number,
                    $episode->started_at?->format('d/m/Y H:i'),
                    $episode->administrative_status?->label(),
                    $episode->medicalDischarge?->type->label(),
                    $episode->medicalDischarge?->discharged_at?->format('d/m/Y H:i'),
                    $episode->administrative_exit_type?->label(),
                    $episode->administrative_exit_at?->format('d/m/Y H:i'),
                ];

                if ($withAmounts) {
                    $account = $accounts[$episode->id] ?? null;

                    array_push(
                        $row,
                        $account['invoiced_amount'] ?? '',
                        $account['paid_amount'] ?? '',
                        $account['balance_amount'] ?? '',
                        // Une fois le passage clos, « non facturé » n'a plus de sens.
                        $episode->administrative_exit_type === null ? ($account['pending_amount'] ?? '') : '',
                        $episode->administrative_exit_balance ?? '',
                    );
                }

                return $row;
            })->all(),
        );
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

    /**
     * ADR-090 (amendement du 2026-09-20) — porter sur une facture les
     * prestations qui attendent encore, sans quitter l'écran des sorties.
     *
     * La sortie est refusée tant qu'il en reste (`RecordAdministrativeExitAction`) :
     * ce geste est le chemin pour lever ce refus. Il compose deux actions déjà
     * en place — `CreateInvoiceAction`, puis `ValidateInvoiceAction` pour qui a
     * `billing.validate` — sans aucune règle nouvelle. Les prestations sont
     * relues ici, jamais reçues du navigateur : une liste envoyée par l'écran
     * pourrait avoir vieilli, ou être forgée.
     *
     * Sans `billing.validate`, la facture reste en brouillon et le message le
     * dit : elle ne peut alors pas encore être encaissée. Ce geste n'encaisse
     * rien (ADR-012) ; le règlement reste à la Caisse.
     */
    public function invoicePending(
        Request $request,
        Episode $episode,
        InvoicePendingPrestationsAction $invoicePending,
    ): RedirectResponse {
        $result = $invoicePending->execute($episode, $request->user());

        if ($result === null) {
            return back()->with('status', 'Aucune prestation en attente de facturation.');
        }

        $number = $result['invoice']->invoice_number;

        return back()->with('status', $result['validated']
            ? "Facture {$number} créée et validée : à encaisser à la Caisse avant la sortie."
            : "Facture {$number} créée en brouillon : elle doit être validée avant de pouvoir être encaissée.");
    }
}
