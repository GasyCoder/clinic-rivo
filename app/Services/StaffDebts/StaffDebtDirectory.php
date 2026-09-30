<?php

namespace App\Services\StaffDebts;

use App\Actions\StaffDebts\RequestStaffDebtAction;
use App\Enums\CashSessionStatus;
use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtRepaymentSource;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
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
    /** Les vues de la liste, exclusives : la somme des comptes fait le total. */
    public const VIEWS = [
        'a-decider' => [StaffDebtStatus::Requested],
        'a-verser' => [StaffDebtStatus::Approved],
        'en-cours' => [StaffDebtStatus::Active],
        'closes' => [StaffDebtStatus::Settled, StaffDebtStatus::WrittenOff, StaffDebtStatus::Refused, StaffDebtStatus::Cancelled],
    ];

    public function __construct(private readonly StaffDebtLedger $ledger) {}

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
            ->with(['repayments.salaryPayment:id,uuid,period'])
            ->latest('id')
            ->get();

        $blocker = match (true) {
            $employee === null => 'Votre compte n’est relié à aucune fiche du personnel. Demandez au RH de le relier (Utilisateurs › Personnel clinique) avant de faire une demande.',
            ! $employee->active || $employee->trashed() => 'Votre fiche n’est plus en poste : aucune demande n’est possible.',
            $debts->contains(fn (StaffDebt $debt) => $debt->status === StaffDebtStatus::Requested) => 'Votre demande en cours attend la décision du DG. Vous pourrez en faire une autre ensuite, ou la retirer.',
            default => null,
        };

        $owing = $debts->filter(fn (StaffDebt $debt) => $debt->status === StaffDebtStatus::Active);

        return [
            'employee' => $employee === null ? null : [
                'name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'employee_number' => $employee->employee_number,
                'in_post' => $employee->active && ! $employee->trashed(),
            ],
            'can_request' => $user->can('staff_debts.request') && $blocker === null,
            'request_blocker' => $user->can('staff_debts.request') ? $blocker : 'Demander une dette demande le droit « staff_debts.request ».',
            'current_month' => now()->format('Y-m'),
            'summary' => [
                'balance' => Money::fromMinor((int) $owing->sum(fn (StaffDebt $debt) => $debt->balanceMinor())),
                'active' => $owing->count(),
                'pending' => $debts->where('status', StaffDebtStatus::Requested)->count(),
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
        $view = array_key_exists((string) $view, self::VIEWS) ? $view : $this->defaultView();
        $search = Str::squish((string) $search);
        $base = fn (): Builder => StaffDebt::query()->when($search !== '', fn (Builder $query) => $this->search($query, $search));

        $counts = collect(self::VIEWS)->map(fn (array $statuses) => $base()->whereIn('status', array_map(fn ($status) => $status->value, $statuses))->count())->all();
        $today = now();

        $debts = $base()->whereIn('status', array_map(fn ($status) => $status->value, self::VIEWS[$view]))
            ->with('repayments')
            ->orderByRaw($view === 'closes' ? 'updated_at desc' : 'requested_at asc')
            ->orderBy('id')
            ->limit(300)
            ->get();

        $active = StaffDebt::query()->where('status', StaffDebtStatus::Active->value)->with('repayments')->get();

        return [
            'view' => $view,
            'search' => $search,
            'counts' => $counts,
            'summary' => [
                'balance' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => $debt->balanceMinor())),
                'arrears' => Money::fromMinor((int) $active->sum(fn (StaffDebt $debt) => $this->ledger->arrearsMinor($debt, $today))),
                'to_decide' => $counts['a-decider'],
                'to_disburse' => $counts['a-verser'],
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
        $debt->loadMissing(['repayments.salaryPayment:id,uuid,period', 'requester:id,name', 'decider:id,name', 'disburser:id,name', 'writer:id,name', 'canceller:id,name']);
        $today = now();
        $plan = $this->plan($debt);

        return [
            ...$this->row($debt, $today),
            'reason' => $debt->reason,
            'requested' => [
                'amount' => (string) $debt->requested_amount,
                'installment_amount' => (string) $debt->requested_installment,
                'first_period' => $debt->requested_first_period?->format('Y-m'),
                'plan' => StaffDebtLedger::plan(Money::toMinor((string) $debt->requested_amount), Money::toMinor((string) $debt->requested_installment), $debt->requested_first_period),
            ],
            'granted' => $debt->amount === null ? null : [
                'amount' => (string) $debt->amount,
                'installment_amount' => (string) $debt->installment_amount,
                'first_period' => $debt->first_period?->format('Y-m'),
                'repayment_mode' => $debt->repayment_mode?->value,
                'repayment_mode_label' => $debt->repayment_mode?->label(),
                'plan' => $plan,
                'adjusted' => $debt->requested_amount !== null && (
                    (string) $debt->amount !== (string) $debt->requested_amount
                    || (string) $debt->installment_amount !== (string) $debt->requested_installment
                    || $debt->first_period?->format('Y-m') !== $debt->requested_first_period?->format('Y-m')
                ),
            ],
            'decision_note' => $debt->decision_note,
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
        $debts = StaffDebt::query()->where('status', StaffDebtStatus::Active->value)->with('repayments')->orderBy('employee_name')->get();

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

        return StaffDebtLedger::plan(Money::toMinor((string) $debt->amount), Money::toMinor((string) $debt->installment_amount), $debt->first_period);
    }

    /** Ce qu'aide à décider : sa fonction, ses autres dettes, et son salaire avec le droit de le voir. @return array<string, mixed>|null */
    private function employee(StaffDebt $debt, User $viewer): ?array
    {
        $employee = $debt->employee?->loadMissing('jobTitle:id,label', 'department:id,label');
        if ($employee === null) {
            return null;
        }

        $others = StaffDebt::query()->where('employee_id', $employee->getKey())->whereKeyNot($debt->getKey())
            ->where('status', StaffDebtStatus::Active->value)->with('repayments')->get();
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
            'withdraw' => $own && $debt->status === StaffDebtStatus::Requested,
            'decide' => $decide && $debt->status === StaffDebtStatus::Requested,
            'adjust' => $decide && in_array($debt->status, [StaffDebtStatus::Approved, StaffDebtStatus::Active], true),
            'cancel' => $decide && $debt->status === StaffDebtStatus::Approved,
            'write_off' => $viewer->can('staff_debts.write_off') && $debt->status === StaffDebtStatus::Active && $debt->balanceMinor() > 0,
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

        if ($debt->settled_at !== null) {
            $events[] = ['key' => 'settled', 'at' => $debt->settled_at->toIso8601String(), 'by' => null, 'label' => 'Soldée', 'detail' => null];
        }

        return collect($events)->sortBy('at')->values()->all();
    }

    /** La vue d'ouverture : ce qui attend un geste, sinon les dettes en cours. */
    private function defaultView(): string
    {
        foreach (['a-decider', 'a-verser'] as $view) {
            if (StaffDebt::query()->whereIn('status', array_map(fn ($status) => $status->value, self::VIEWS[$view]))->exists()) {
                return $view;
            }
        }

        return 'en-cours';
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
