<?php

namespace App\Services\StaffDebts;

use App\Actions\StaffDebts\RequestStaffDebtAction;
use App\Enums\CashSessionStatus;
use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtRepaymentSource;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
use App\Models\StaffDebtPenalty;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ADR-228 — ce que chaque écran lit d'une dette du personnel, écrit une fois :
 * l'employé (ses dettes), le RH et le DG (la liste, une dette), la Caisse (ce qui
 * se rembourse en espèces), et le portail (les demandes à décider).
 *
 * Le motif d'une demande est personnel : il est servi à l'employé, au RH et au DG,
 * jamais à la Caisse, qui n'a besoin que du nom, du numéro et du reste dû. Le salaire
 * n'est servi qu'avec le droit de le voir (ADR-206).
 */
final class StaffDebtDirectory
{
    /**
     * Les vues de la liste, exclusives : la somme des comptes fait le total. ADR-230 — une
     * dette en remboursement d'une personne partie, pas encore réglée, est « à régler au
     * départ » et quitte « en cours ».
     */
    public const VIEWS = ['a-decider', 'a-verser', 'depart', 'en-cours', 'closes'];

    private const CLOSED = [StaffDebtStatus::Settled, StaffDebtStatus::WrittenOff, StaffDebtStatus::Refused, StaffDebtStatus::Cancelled];

    /** Les relations qu'un reste dû lit : remboursements et pénalités. */
    private const MONEY = ['repayments', 'penalties', 'employee'];

    public function __construct(
        private readonly StaffDebtLedger $ledger,
        private readonly StaffDebtRules $rules,
    ) {}

    /**
     * L'espace de l'employé : ses dettes, et s'il peut en demander une.
     *
     * @return array<string, mixed>
     */
    public function mine(User $user): array
    {
        $employee = RequestStaffDebtAction::employeeOf($user);
        $debts = $employee === null ? collect() : StaffDebt::query()
            ->where('employee_id', $employee->getKey())
            ->with(['repayments.salaryPayment:id,uuid,period', 'penalties', 'employee'])
            ->latest('id')
            ->get();

        $blocker = match (true) {
            $employee === null => 'Votre compte n’est relié à aucune fiche du personnel. Demandez au RH de le relier (Utilisateurs › Personnel clinique) avant de faire une demande.',
            ! $employee->active || $employee->trashed() => 'Votre fiche n’est plus en poste : aucune demande n’est possible.',
            $debts->contains(fn (StaffDebt $debt) => $debt->status === StaffDebtStatus::Requested) => 'Votre demande en cours attend la décision du DG. Vous pourrez en faire une autre ensuite, ou la retirer.',
            // ADR-229 — les règles du site : demandes ouvertes, stagiaires, ancienneté, dettes en cours.
            default => $this->rules->requestBlocker($employee),
        };
        $cap = $employee !== null ? $this->rules->installmentCapMinor($employee) : null;

        $owing = $debts->filter(fn (StaffDebt $debt) => $debt->status === StaffDebtStatus::Active);
        $today = now();

        // Le prochain mois où quelque chose se rembourse, toutes dettes en cours confondues.
        $firsts = $owing->map(fn (StaffDebt $debt) => $this->ledger->projection($debt, $today)[0] ?? null)->filter();
        $nextPeriod = $firsts->min('period');

        return [
            'employee' => $employee === null ? null : [
                'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'employee_number' => $employee->employee_number,
                'in_post' => $employee->active && ! $employee->trashed(),
            ],
            'can_request' => $user->can('staff_debts.request') && $blocker === null,
            'request_blocker' => $user->can('staff_debts.request') ? $blocker : 'Demander une dette demande le droit « staff_debts.request ».',
            'current_month' => now()->format('Y-m'),
            // Les règles du site, pour l'aperçu pendant la saisie ; le serveur revérifie tout.
            // Le plafond de mensualité est tiré de son propre salaire : jamais le salaire lui-même.
            'rules' => [...$this->rules->present(), 'max_installment' => $cap !== null ? Money::fromMinor($cap['available']) : null],
            'summary' => [
                'balance' => Money::fromMinor((int) $owing->sum(fn (StaffDebt $debt) => $debt->balanceMinor())),
                'active' => $owing->count(),
                'pending' => $debts->where('status', StaffDebtStatus::Requested)->count(),
                'repaid' => Money::fromMinor((int) $debts->sum(fn (StaffDebt $debt) => $debt->repaidMinor())),
                'arrears' => Money::fromMinor((int) $owing->sum(fn (StaffDebt $debt) => $this->ledger->arrearsMinor($debt, $today))),
                'next' => $nextPeriod === null ? null : [
                    'period' => $nextPeriod,
                    'amount' => Money::fromMinor((int) $firsts->where('period', $nextPeriod)->sum(fn (array $month) => Money::toMinor($month['amount']))),
                ],
            ],
            'debts' => $debts->map(fn (StaffDebt $debt) => $this->detail($debt, $user, withEmployee: false))->values()->all(),
        ];
    }

    /**
     * La liste du RH et du DG : une vue, une recherche, les comptes de chaque vue.
     *
     * @return array<string, mixed>
     */
    public function listing(?string $view, ?string $search): array
    {
        $view = in_array((string) $view, self::VIEWS, true) ? (string) $view : $this->defaultView();
        $search = Str::squish((string) $search);
        $base = fn (): Builder => StaffDebt::query()->when($search !== '', fn (Builder $query) => $this->search($query, $search));

        $counts = collect(self::VIEWS)->mapWithKeys(fn (string $key) => [$key => $this->inView($base(), $key)->count()])->all();
        $today = now();

        $debts = $this->inView($base(), $view)
            ->with(self::MONEY)
            ->orderByRaw($view === 'closes' ? 'updated_at desc' : 'requested_at asc')
            ->orderBy('id')
            ->limit(300)
            ->get();

        $active = StaffDebt::query()->where('status', StaffDebtStatus::Active->value)->with(self::MONEY)->get();

        return [
            'view' => $view,
            'search' => $search,
            'counts' => $counts,
            'summary' => [
                'balance' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => $debt->balanceMinor())),
                'arrears' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => $this->ledger->arrearsMinor($debt, $today))),
                'to_decide' => $counts['a-decider'],
                'to_disburse' => $counts['a-verser'],
                'to_settle' => $counts['depart'],
                'penalties' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => $debt->penaltiesMinor())),
            ],
            'debts' => $debts->map(fn (StaffDebt $debt) => $this->row($debt, $today))->values()->all(),
        ];
    }

    /** Une ligne de la liste. @return array<string, mixed> */
    public function row(StaffDebt $debt, ?Carbon $today = null): array
    {
        $today ??= now();
        $next = $this->ledger->nextPeriod($debt, $today);

        return [
            'uuid' => $debt->uuid,
            'number' => $debt->number,
            'employee_name' => $debt->employee_name,
            'employee_number' => $debt->employee_number,
            'status' => $debt->status->value,
            'status_label' => $debt->status->label(),
            'status_tone' => $debt->status->tone(),
            'requested_amount' => (string) $debt->requested_amount,
            'amount' => $debt->amount !== null ? (string) $debt->amount : null,
            // ADR-229 — l'intérêt figé et ce qui est à rembourser en tout.
            'interest_amount' => (string) ($debt->amount !== null ? $debt->interest_amount : $debt->requested_interest_amount),
            'total_due' => Money::fromMinor($debt->amount !== null ? $debt->totalDueMinor() : $debt->requestedTotalMinor()),
            'derogated' => ! empty($debt->derogations),
            // ADR-230 — les pénalités encore dues, et un départ à régler.
            'penalties' => Money::fromMinor($debt->penaltiesMinor()),
            'awaits_departure' => $debt->awaitsDepartureSettlement(),
            'departure_settled' => $debt->departure_settled_at !== null,
            'installment_amount' => (string) ($debt->installment_amount ?? $debt->requested_installment),
            'repayment_mode' => $debt->repayment_mode?->value,
            'repayment_mode_label' => $debt->repayment_mode?->label(),
            'balance' => Money::fromMinor($debt->balanceMinor()),
            'arrears' => Money::fromMinor($this->ledger->arrearsMinor($debt, $today)),
            'next_period' => $next?->format('Y-m'),
            'requested_at' => $debt->requested_at?->toIso8601String(),
        ];
    }

    /**
     * Une dette, pour l'employé qui l'a demandée ou pour le RH et le DG.
     *
     * @return array<string, mixed>
     */
    public function detail(StaffDebt $debt, User $viewer, bool $withEmployee = true): array
    {
        $debt->loadMissing(['repayments.salaryPayment:id,uuid,period', 'penalties.waiver:id,name', 'employee', 'requester:id,name', 'decider:id,name', 'disburser:id,name', 'writer:id,name', 'canceller:id,name', 'departureSettler:id,name']);
        $today = now();
        $plan = $this->plan($debt);

        return [
            ...$this->row($debt, $today),
            'reason' => $debt->reason,
            'requested' => [
                'amount' => (string) $debt->requested_amount,
                'installment_amount' => (string) $debt->requested_installment,
                'first_period' => $debt->requested_first_period?->format('Y-m'),
                'interest_amount' => (string) $debt->requested_interest_amount,
                'total' => Money::fromMinor($debt->requestedTotalMinor()),
                'plan' => StaffDebtLedger::plan($debt->requestedTotalMinor(), Money::toMinor((string) $debt->requested_installment), $debt->requested_first_period),
            ],
            'granted' => $debt->amount === null ? null : [
                'amount' => (string) $debt->amount,
                'installment_amount' => (string) $debt->installment_amount,
                'first_period' => $debt->first_period?->format('Y-m'),
                'repayment_mode' => $debt->repayment_mode?->value,
                'repayment_mode_label' => $debt->repayment_mode?->label(),
                'interest' => [
                    'amount' => (string) $debt->interest_amount,
                    'mode' => $debt->interest_mode,
                    'value' => $debt->interest_value !== null ? (string) $debt->interest_value : null,
                    'waived' => (bool) $debt->interest_waived,
                ],
                'total' => Money::fromMinor($debt->totalDueMinor()),
                'plan' => $plan,
                'adjusted' => $debt->requested_amount !== null && (
                    (string) $debt->amount !== (string) $debt->requested_amount
                    || (string) $debt->installment_amount !== (string) $debt->requested_installment
                    || $debt->first_period?->format('Y-m') !== $debt->requested_first_period?->format('Y-m')
                ),
            ],
            'decision_note' => $debt->decision_note,
            'derogations' => $debt->derogations ?? [],
            'penalty' => $this->penalty($debt),
            'departure' => $this->departure($debt),
            'refusal_reason' => $debt->refusal_reason,
            'cancel_reason' => $debt->cancel_reason,
            'write_off_reason' => $debt->write_off_reason,
            'written_off_amount' => (string) $debt->written_off_amount,
            'repaid' => Money::fromMinor($debt->repaidMinor()),
            'disbursement' => $debt->disbursed_on === null ? null : [
                'on' => $debt->disbursed_on->toDateString(),
                'mode' => $debt->disbursement_mode?->value,
                'mode_label' => $debt->disbursement_mode?->label(),
                'reference' => $debt->disbursement_reference,
                'note' => $debt->disbursement_note,
            ],
            'timeline' => $this->timeline($debt),
            'schedule' => $this->ledger->projection($debt, $today),
            'repayments' => $debt->repayments->map(fn (StaffDebtRepayment $repayment) => $this->repayment($repayment))->values()->all(),
            'employee' => $withEmployee ? $this->employee($debt, $viewer) : null,
            // ADR-229 — ce qu'il faut au DG pour décider : les règles du site et, pour sa
            // personne, la mensualité que le salaire permet encore. L'écran avertit, le serveur tranche.
            'rules' => $withEmployee ? $this->decisionRules($debt) : null,
            'can' => $this->abilities($debt, $viewer),
        ];
    }

    /**
     * La Caisse : les dettes en cours de remboursement, avec ce qui se remet ce mois-ci,
     * et les encaissements de la session de ce caissier, qu'il peut encore annuler.
     *
     * @return array<string, mixed>
     */
    public function forCash(User $user): array
    {
        $today = now();
        $debts = StaffDebt::query()->where('status', StaffDebtStatus::Active->value)->with(self::MONEY)->orderBy('employee_name')->get();

        $recent = StaffDebtRepayment::query()
            ->where('source', StaffDebtRepaymentSource::Cash->value)
            ->where('recorded_by', $user->getKey())
            ->whereHas('cashSession', fn ($query) => $query->where('status', CashSessionStatus::Open->value))
            ->with('debt:id,uuid,number,employee_name')
            ->latest('id')
            ->limit(30)
            ->get();

        return [
            'debts' => $debts->map(function (StaffDebt $debt) use ($today): array {
                $arrears = $this->ledger->arrearsMinor($debt, $today);
                $installment = Money::toMinor((string) $debt->installment_amount);
                $thisMonth = $this->ledger->repaidInMonthMinor($debt, $today);
                $suggested = $debt->repayment_mode === StaffDebtRepaymentMode::Cash
                    ? max($arrears, max(0, $installment - $thisMonth))
                    : $installment;

                return [
                    'uuid' => $debt->uuid,
                    'number' => $debt->number,
                    'employee_name' => $debt->employee_name,
                    'employee_number' => $debt->employee_number,
                    'repayment_mode' => $debt->repayment_mode?->value,
                    'repayment_mode_label' => $debt->repayment_mode?->label(),
                    'installment_amount' => (string) $debt->installment_amount,
                    'balance' => Money::fromMinor($debt->balanceMinor()),
                    'penalties' => Money::fromMinor($debt->penaltiesMinor()),
                    'arrears' => Money::fromMinor($arrears),
                    'repaid_this_month' => Money::fromMinor($thisMonth),
                    'suggested_amount' => Money::fromMinor(min($debt->balanceMinor(), max(0, $suggested))),
                ];
            })->values()->all(),
            'recent' => $recent->map(fn (StaffDebtRepayment $repayment) => [
                ...$this->repayment($repayment),
                'debt_number' => $repayment->debt?->number,
                'employee_name' => $repayment->debt?->employee_name,
            ])->values()->all(),
        ];
    }

    /**
     * Le portail : les demandes qui attendent le DG, sans motif ni salaire — il les lit
     * sur l'écran de la dette, par le relais RH (ADR-187).
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return StaffDebt::query()->where('status', StaffDebtStatus::Requested->value)->orderBy('requested_at')->get()
            ->map(fn (StaffDebt $debt) => [
                'uuid' => $debt->uuid,
                'number' => $debt->number,
                'employee_name' => $debt->employee_name,
                'amount' => (string) $debt->requested_amount,
                'requested_at' => $debt->requested_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * ADR-229 — ce que le portail montre d'un site dans « Tous les sites » : les comptes
     * de chaque vue, l'argent en jeu et l'état des demandes. Ni nom, ni motif, ni salaire.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $today = now();
        $counts = collect(self::VIEWS)->mapWithKeys(fn (string $key) => [$key => $this->inView(StaffDebt::query(), $key)->count()])->all();
        $active = StaffDebt::query()->where('status', StaffDebtStatus::Active->value)->with(self::MONEY)->get();
        $arrears = $active->map(fn (StaffDebt $debt) => $this->ledger->arrearsMinor($debt, $today));
        $present = $this->rules->present();

        return [
            'counts' => $counts,
            'balance' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => $debt->balanceMinor())),
            'arrears' => Money::fromMinor((int) $arrears->sum()),
            'late' => $arrears->filter(fn (int $minor) => $minor > 0)->count(),
            'to_disburse_amount' => Money::fromMinor((int) StaffDebt::query()->where('status', StaffDebtStatus::Approved->value)->get()->sum(fn (StaffDebt $debt) => Money::toMinor((string) $debt->amount))),
            'requested_amount' => Money::fromMinor((int) StaffDebt::query()->where('status', StaffDebtStatus::Requested->value)->get()->sum(fn (StaffDebt $debt) => Money::toMinor((string) $debt->requested_amount))),
            'interest' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => Money::toMinor((string) $debt->interest_amount))),
            'penalties' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => $debt->penaltiesMinor())),
            'rules' => [
                'configured' => $present['configured'],
                'requests_open' => $present['requests_open'],
                'amount_limits_set' => $present['amount_limits_set'],
                'accepting_requests' => $present['accepting_requests'],
                'has_interest' => $present['interest_tiers'] !== [],
                'has_penalty' => $present['penalty_rate'] !== null,
            ],
        ];
    }

    /**
     * ADR-229 — la liste en Excel : la vue et la recherche de l'écran (toutes les dettes
     * sans vue), une ligne par dette. Le motif et le salaire n'y sont pas.
     *
     * @return array{view: string, search: string, headers: list<string>, rows: list<list<mixed>>, numbers: list<string>}
     */
    public function export(?string $view, ?string $search): array
    {
        $view = in_array((string) $view, self::VIEWS, true) ? (string) $view : 'toutes';
        $search = Str::squish((string) $search);
        $today = now();

        $debts = StaffDebt::query()
            ->when($view !== 'toutes', fn (Builder $query) => $this->inView($query, $view))
            ->when($search !== '', fn (Builder $query) => $this->search($query, $search))
            ->with(self::MONEY)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->limit(5000)
            ->get();

        $amount = fn (mixed $value): ?float => $value === null ? null : (float) $value;

        return [
            'view' => $view,
            'search' => $search,
            'headers' => [
                'N° de dette', 'Personne', 'Matricule', 'État', 'Demandée le', 'Montant demandé', 'Montant accordé',
                'Intérêt', 'Total à rembourser', 'Mensualité', 'Remboursement', 'Premier mois', 'Versée le',
                'Remboursé', 'Remis', 'Pénalités', 'Reste dû', 'En retard', 'Dérogation', 'Départ',
            ],
            'rows' => $debts->map(fn (StaffDebt $debt) => [
                $debt->number,
                $debt->employee_name,
                $debt->employee_number,
                $debt->status->label(),
                $debt->requested_at?->format('d/m/Y'),
                $amount($debt->requested_amount),
                $amount($debt->amount),
                $debt->amount !== null ? (float) $debt->interest_amount : (float) $debt->requested_interest_amount,
                (float) Money::fromMinor($debt->amount !== null ? $debt->totalDueMinor() : $debt->requestedTotalMinor()),
                $amount($debt->installment_amount ?? $debt->requested_installment),
                $debt->repayment_mode?->label(),
                ($debt->first_period ?? $debt->requested_first_period)?->format('m/Y'),
                $debt->disbursed_on?->format('d/m/Y'),
                (float) Money::fromMinor($debt->repaidMinor()),
                $amount($debt->written_off_amount),
                (float) Money::fromMinor($debt->penaltiesMinor()),
                $debt->status === StaffDebtStatus::Active ? (float) Money::fromMinor($debt->balanceMinor()) : null,
                $debt->status === StaffDebtStatus::Active ? (float) Money::fromMinor($this->ledger->arrearsMinor($debt, $today)) : null,
                empty($debt->derogations) ? null : implode(' ', $debt->derogations),
                $debt->departure_settled_at !== null ? 'Réglé le '.$debt->departure_settled_at->format('d/m/Y') : ($debt->awaitsDepartureSettlement() ? 'À régler' : null),
            ])->values()->all(),
            'numbers' => $debts->pluck('number')->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function repayment(StaffDebtRepayment $repayment): array
    {
        return [
            'uuid' => $repayment->uuid,
            'source' => $repayment->source->value,
            'source_label' => $repayment->source->label(),
            'period' => $repayment->period?->format('Y-m'),
            'amount' => (string) $repayment->amount,
            'receipt_number' => $repayment->receipt_number,
            'note' => $repayment->note,
            'recorded_at' => $repayment->recorded_at?->toIso8601String(),
            'recorded_by' => RemoteActorAttribution::name($repayment->recorder?->name, $repayment->external_recorded_by_name),
            'reversed_at' => $repayment->reversed_at?->toIso8601String(),
            'reversed_by' => RemoteActorAttribution::name($repayment->reverser?->name, $repayment->external_reversed_by_name),
            'reverse_reason' => $repayment->reverse_reason,
        ];
    }

    /** Le plan accordé, ou celui qui reste une fois versée. @return array<string, mixed>|null */
    private function plan(StaffDebt $debt): ?array
    {
        if ($debt->amount === null || $debt->installment_amount === null || $debt->first_period === null) {
            return null;
        }

        return StaffDebtLedger::plan($debt->totalDueMinor(), Money::toMinor((string) $debt->installment_amount), $debt->first_period);
    }

    /** @return array<string, mixed> */
    private function decisionRules(StaffDebt $debt): array
    {
        $employee = $debt->employee;
        $cap = $employee !== null ? $this->rules->installmentCapMinor($employee, $debt->getKey()) : null;

        return [
            ...$this->rules->present(),
            'installment_cap' => $cap === null ? null : [
                'cap' => Money::fromMinor($cap['cap']),
                'engaged' => Money::fromMinor($cap['engaged']),
                'available' => Money::fromMinor($cap['available']),
            ],
            'engaged_debts' => $employee !== null ? $this->rules->engagedDebts($employee, $debt->getKey())->count() : 0,
        ];
    }

    /** Ce qu'aide à décider : sa fonction, ses autres dettes, et son salaire avec le droit de le voir. @return array<string, mixed>|null */
    private function employee(StaffDebt $debt, User $viewer): ?array
    {
        $employee = $debt->employee?->loadMissing('jobTitle:id,label', 'department:id,label');
        if ($employee === null) {
            return null;
        }

        $others = StaffDebt::query()->where('employee_id', $employee->getKey())->whereKeyNot($debt->getKey())
            ->where('status', StaffDebtStatus::Active->value)->with(['repayments', 'penalties'])->get();
        $salary = $viewer->can('employees.payroll.view') && $employee->remuneration_type?->hasAmount() && (float) $employee->remuneration_amount > 0
            ? (string) $employee->remuneration_amount
            : null;

        return [
            'uuid' => $employee->uuid,
            'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
            'employee_number' => $employee->employee_number,
            'job_title' => $employee->jobTitle?->label ?? $employee->profession,
            'department' => $employee->department?->label,
            'in_post' => $employee->active && ! $employee->trashed(),
            'hired_on' => $employee->hire_date?->toDateString(),
            'salary' => $salary,
            'salary_visible' => $viewer->can('employees.payroll.view'),
            'salary_declared' => (bool) ($employee->remuneration_type?->hasAmount() && (float) $employee->remuneration_amount > 0),
            'other_balance' => Money::fromMinor((int) $others->sum(fn (StaffDebt $other) => $other->balanceMinor())),
            'other_active' => $others->count(),
            'other_installments' => Money::fromMinor((int) $others->filter(fn (StaffDebt $other) => $other->repayment_mode === StaffDebtRepaymentMode::Salary)
                ->sum(fn (StaffDebt $other) => Money::toMinor((string) $other->installment_amount))),
        ];
    }

    /** @return array<string, bool> */
    private function abilities(StaffDebt $debt, User $viewer): array
    {
        $employee = RequestStaffDebtAction::employeeOf($viewer);
        $own = $employee !== null && $employee->getKey() === $debt->employee_id;
        $decide = $viewer->can('staff_debts.decide');

        return [
            'remind' => $decide && $debt->status === StaffDebtStatus::Active && $this->ledger->arrearsMinor($debt, now()) > 0,
            'withdraw' => $own && $debt->status === StaffDebtStatus::Requested,
            'decide' => $decide && $debt->status === StaffDebtStatus::Requested,
            'adjust' => $decide && in_array($debt->status, [StaffDebtStatus::Approved, StaffDebtStatus::Active], true),
            'cancel' => $decide && $debt->status === StaffDebtStatus::Approved,
            'write_off' => $viewer->can('staff_debts.write_off') && $debt->status === StaffDebtStatus::Active && $debt->balanceMinor() > 0,
            // ADR-230 — remettre une pénalité, régler un départ, imprimer les documents.
            'waive_penalty' => $viewer->can('staff_debts.write_off'),
            'settle_departure' => $decide && $debt->awaitsDepartureSettlement(),
            'disburse' => $viewer->can('staff_debts.disburse') && $debt->status === StaffDebtStatus::Approved,
        ];
    }

    /** Ce qui est arrivé à la dette, dans l'ordre. @return list<array<string, mixed>> */
    private function timeline(StaffDebt $debt): array
    {
        $events = [[
            'key' => 'requested', 'at' => $debt->requested_at?->toIso8601String(), 'by' => $debt->requester?->name,
            'label' => 'Demandée', 'detail' => StaffDebtNotifier::money($debt->requested_amount).' — '.StaffDebtNotifier::money($debt->requested_installment).' par mois',
        ]];

        if ($debt->decided_at !== null) {
            $events[] = $debt->status === StaffDebtStatus::Refused
                ? ['key' => 'refused', 'at' => $debt->decided_at->toIso8601String(), 'by' => RemoteActorAttribution::name($debt->decider?->name, $debt->external_decided_by_name), 'label' => 'Refusée', 'detail' => $debt->refusal_reason]
                : ['key' => 'approved', 'at' => $debt->decided_at->toIso8601String(), 'by' => RemoteActorAttribution::name($debt->decider?->name, $debt->external_decided_by_name), 'label' => 'Accordée', 'detail' => $debt->decision_note];
        }

        if ($debt->disbursed_at !== null) {
            $events[] = ['key' => 'disbursed', 'at' => $debt->disbursed_at->toIso8601String(), 'by' => RemoteActorAttribution::name($debt->disburser?->name, $debt->external_disbursed_by_name),
                'label' => 'Versée', 'detail' => $debt->disbursed_on?->format('d/m/Y').' · '.$debt->disbursement_mode?->label()];
        }

        if ($debt->written_off_at !== null) {
            $events[] = ['key' => 'written_off', 'at' => $debt->written_off_at->toIso8601String(), 'by' => RemoteActorAttribution::name($debt->writer?->name, $debt->external_written_off_by_name),
                'label' => 'Reste remis', 'detail' => StaffDebtNotifier::money($debt->written_off_amount).' — '.$debt->write_off_reason];
        }

        if ($debt->cancelled_at !== null) {
            $events[] = ['key' => 'cancelled', 'at' => $debt->cancelled_at->toIso8601String(), 'by' => RemoteActorAttribution::name($debt->canceller?->name, $debt->external_cancelled_by_name),
                'label' => 'Annulée', 'detail' => $debt->cancel_reason];
        }

        if ($debt->departure_settled_at !== null) {
            $events[] = ['key' => 'departure', 'at' => $debt->departure_settled_at->toIso8601String(),
                'by' => RemoteActorAttribution::name($debt->departureSettler?->name, $debt->external_departure_settled_by_name),
                'label' => 'Départ réglé', 'detail' => $debt->departure_terms['note'] ?? null];
        }

        foreach ($debt->penalties as $penalty) {
            $events[] = ['key' => 'penalty', 'at' => $penalty->assessed_at?->toIso8601String(), 'by' => null,
                'label' => 'Pénalité de retard'.($penalty->waived_at !== null ? ' (remise)' : ''),
                'detail' => StaffDebtNotifier::money($penalty->amount).' — '.StaffDebtPenalties::rate($penalty->rate).' % de '.StaffDebtNotifier::money($penalty->base_amount).' en retard ('.$penalty->period->translatedFormat('F Y').')'];
        }

        if ($debt->settled_at !== null) {
            $events[] = ['key' => 'settled', 'at' => $debt->settled_at->toIso8601String(), 'by' => null, 'label' => 'Soldée', 'detail' => null];
        }

        return collect($events)->sortBy('at')->values()->all();
    }

    /** La vue d'ouverture : ce qui attend un geste, sinon les dettes en cours. */
    private function defaultView(): string
    {
        foreach (['a-decider', 'a-verser', 'depart'] as $view) {
            if ($this->inView(StaffDebt::query(), $view)->exists()) {
                return $view;
            }
        }

        return 'en-cours';
    }

    private function inView(Builder $query, string $view): Builder
    {
        return match ($view) {
            'a-decider' => $query->where('status', StaffDebtStatus::Requested->value),
            'a-verser' => $query->where('status', StaffDebtStatus::Approved->value),
            'depart' => $query->awaitingDepartureSettlement(),
            'en-cours' => $query->repayingNormally(),
            default => $query->whereIn('status', array_map(fn (StaffDebtStatus $status) => $status->value, self::CLOSED)),
        };
    }

    /**
     * ADR-230 — la règle de pénalité figée sur la dette et les pénalités liquidées.
     *
     * @return array<string, mixed>
     */
    private function penalty(StaffDebt $debt): array
    {
        $rule = $debt->penaltyRule();

        return [
            'rule' => $rule === null ? null : [
                ...$rule,
                'cap' => $rule['cap_rate'] !== null && $debt->amount !== null
                    ? Money::fromMinor(Money::percentage(Money::toMinor((string) $debt->amount), $rule['cap_rate']))
                    : null,
            ],
            'due' => Money::fromMinor($debt->penaltiesMinor()),
            'items' => $debt->penalties->map(fn (StaffDebtPenalty $penalty) => [
                'uuid' => $penalty->uuid,
                'period' => $penalty->period->format('Y-m'),
                'base_amount' => (string) $penalty->base_amount,
                'rate' => (string) $penalty->rate,
                'amount' => (string) $penalty->amount,
                'assessed_at' => $penalty->assessed_at?->toIso8601String(),
                'waived_at' => $penalty->waived_at?->toIso8601String(),
                'waived_by' => RemoteActorAttribution::name($penalty->waiver?->name, $penalty->external_waived_by_name),
                'waiver_reason' => $penalty->waiver_reason,
            ])->values()->all(),
        ];
    }

    /**
     * ADR-230 — le départ de la personne : à régler, ou réglé et comment.
     *
     * @return array<string, mixed>|null
     */
    private function departure(StaffDebt $debt): ?array
    {
        $employee = $debt->employee;
        $left = $employee === null || ! $employee->active || $employee->trashed();

        if (! $left && $debt->departure_settled_at === null) {
            return null;
        }

        $principalLeft = max(0, $debt->principalOwedMinor() - $debt->repaidMinor());

        return [
            'employee_left' => $left,
            'left_on' => $employee?->deleted_at?->toDateString(),
            'awaiting' => $debt->awaitsDepartureSettlement(),
            'balance' => Money::fromMinor($debt->balanceMinor()),
            'principal_left' => Money::fromMinor(min($principalLeft, $debt->balanceMinor())),
            'penalties_due' => Money::fromMinor($debt->penaltiesMinor()),
            'settled_at' => $debt->departure_settled_at?->toIso8601String(),
            'settled_by' => RemoteActorAttribution::name($debt->departureSettler?->name, $debt->external_departure_settled_by_name),
            'terms' => $debt->departure_terms,
        ];
    }

    private function search(Builder $query, string $search): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return $query->where(fn (Builder $query) => $query
            ->where('employee_name', 'like', $like)
            ->orWhere('number', 'like', $like)
            ->orWhere('employee_number', 'like', $like));
    }
}
