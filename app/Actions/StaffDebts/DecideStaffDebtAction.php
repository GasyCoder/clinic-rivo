<?php

namespace App\Actions\StaffDebts;

use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\Employee;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\StaffDebts\StaffDebtLedger;
use App\Services\StaffDebts\StaffDebtNotifier;
use App\Services\StaffDebts\StaffDebtPenalties;
use App\Services\StaffDebts\StaffDebtRules;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use App\Support\StaffDebts\StaffDebtTerms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-228 — les décisions du DG sur une dette du personnel : l'accorder (en ajustant
 * montant, mensualité ou premier mois, et en choisissant retenue sur salaire ou
 * espèces), la refuser avec un motif, l'ajuster ensuite, annuler un accord pas encore
 * versé, ou remettre le reste d'une dette en cours. Chaque geste se fait sur la ligne
 * verrouillée, s'audite, et l'employé en est prévenu.
 *
 * Une retenue sur salaire exige un salaire déclaré (ADR-206) : sans lui, la paie
 * n'aurait rien sur quoi retenir.
 *
 * ADR-229 — l'intérêt de la tranche est figé à l'accord (le DG peut le remettre), et
 * recalculé si le montant change avant le versement. Des conditions qui dépassent les
 * limites du site sont une dérogation : refusée tant que le DG ne la confirme pas
 * (`accept_derogations`), puis écrite sur la dette et dans l'audit.
 */
class DecideStaffDebtAction
{
    public function __construct(
        private readonly StaffDebtLedger $ledger,
        private readonly StaffDebtNotifier $notifier,
        private readonly Auditor $auditor,
        private readonly StaffDebtRules $rules,
        private readonly StaffDebtPenalties $penalties,
    ) {}

    /** @param  array{amount: string, installment_amount: string, first_period: string, repayment_mode: string, note?: ?string, waive_interest?: bool, waive_penalty?: bool, accept_derogations?: bool}  $terms */
    public function approve(StaffDebt $debt, array $terms, User $actor): StaffDebt
    {
        $this->authorize($actor, 'staff_debts.decide', 'Seul le DG accorde une dette.');

        $debt = DB::transaction(function () use ($debt, $terms, $actor): StaffDebt {
            $debt = $this->lock($debt);

            if ($debt->status !== StaffDebtStatus::Requested) {
                throw ValidationException::withMessages(['debt' => 'Cette demande a déjà été décidée.']);
            }

            $employee = $debt->employee;
            if (! $employee?->active || $employee->trashed()) {
                throw ValidationException::withMessages(['debt' => 'Cette personne n’est plus en poste : refusez la demande.']);
            }

            $waived = (bool) ($terms['waive_interest'] ?? false);
            // ADR-230 — la règle de pénalité du site est figée sur la dette, sauf si le DG l'écarte.
            $penalty = ($terms['waive_penalty'] ?? false) ? null : $this->rules->penaltyRule();
            [$amount, $installment, $first, $mode, $interest] = $this->terms($terms, $employee, interest: fn (int $amount) => $waived ? null : $this->rules->interest($amount));
            $derogations = $this->derogations($employee, $amount, $installment, $terms);

            $debt->forceFill([
                'status' => StaffDebtStatus::Approved,
                'pending_key' => null,
                'amount' => $amount,
                'installment_amount' => $installment,
                ...$this->interestFields($interest, $waived),
                'penalty_rate' => $penalty['penalty_rate'] ?? null,
                'penalty_grace_days' => $penalty['penalty_grace_days'] ?? null,
                'penalty_cap_rate' => $penalty['penalty_cap_rate'] ?? null,
                'first_period' => $first->toDateString(),
                'repayment_mode' => $mode,
                'decision_note' => filled($terms['note'] ?? null) ? Str::squish($terms['note']) : null,
                'derogations' => $derogations ?: null,
                'decided_at' => now(),
                'decided_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('decided', $actor),
            ])->save();

            return $debt;
        });

        $plan = StaffDebtLedger::plan($debt->totalDueMinor(), Money::toMinor((string) $debt->installment_amount), $debt->first_period);
        $this->notifier->employee(
            $debt,
            'approved',
            'Votre demande de dette est accordée',
            StaffDebtNotifier::money($debt->amount)
                .((float) $debt->interest_amount > 0 ? ' + intérêt '.StaffDebtNotifier::money($debt->interest_amount).' = '.StaffDebtNotifier::money(Money::fromMinor($debt->totalDueMinor())).' à rembourser' : '')
                .' — '.$plan['count'].' mensualité'.($plan['count'] > 1 ? 's' : '').' de '.StaffDebtNotifier::money($debt->installment_amount)
                .', '.mb_strtolower($debt->repayment_mode->label()).'. Elle vous sera versée.',
        );

        return $debt;
    }

    public function refuse(StaffDebt $debt, string $reason, User $actor): StaffDebt
    {
        $this->authorize($actor, 'staff_debts.decide', 'Seul le DG refuse une dette.');

        $debt = DB::transaction(function () use ($debt, $reason, $actor): StaffDebt {
            $debt = $this->lock($debt);

            if ($debt->status !== StaffDebtStatus::Requested) {
                throw ValidationException::withMessages(['debt' => 'Cette demande a déjà été décidée.']);
            }

            $debt->forceFill([
                'status' => StaffDebtStatus::Refused,
                'pending_key' => null,
                'refusal_reason' => $this->reason($reason),
                'decided_at' => now(),
                'decided_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('decided', $actor),
            ])->save();

            return $debt;
        });

        $this->notifier->employee($debt, 'refused', 'Votre demande de dette est refusée', 'Motif : '.$debt->refusal_reason);

        return $debt;
    }

    /**
     * Ajuster une dette accordée : avant le versement, tout peut changer ; une fois
     * versée, le montant est ce qui a été remis — seules la mensualité, le mois de
     * reprise des remboursements et leur mode changent.
     *
     * @param  array{amount?: ?string, installment_amount: string, first_period: string, repayment_mode: string, reason: string}  $terms
     */
    public function adjust(StaffDebt $debt, array $terms, User $actor): StaffDebt
    {
        $this->authorize($actor, 'staff_debts.decide', 'Seul le DG ajuste une dette.');

        $debt = DB::transaction(function () use ($debt, $terms, $actor): StaffDebt {
            $debt = $this->lock($debt);

            if (! in_array($debt->status, [StaffDebtStatus::Approved, StaffDebtStatus::Active], true)) {
                throw ValidationException::withMessages(['debt' => 'Seule une dette accordée ou en remboursement s’ajuste.']);
            }

            $disbursed = $debt->status === StaffDebtStatus::Active;
            if (! $disbursed && blank($terms['amount'] ?? null)) {
                throw ValidationException::withMessages(['amount' => 'Indiquez le montant.']);
            }
            $terms['amount'] = $disbursed ? (string) $debt->amount : $terms['amount'];

            // Un premier mois déjà passé reste accepté tant qu'on n'y touche pas : une dette en cours a commencé.
            $keepsPeriod = ($terms['first_period'] ?? null) === $debt->first_period?->format('Y-m');
            // Versée, l'intérêt reste celui de l'accord ; avant, il suit le montant (sauf s'il a été remis).
            $interestFor = $disbursed ? null : fn (int $amount) => $debt->interest_waived ? null : $this->rules->interest($amount);
            [$amount, $installment, $first, $mode, $interest] = $this->terms($terms, $debt->employee, $keepsPeriod, $interestFor, $disbursed ? $debt->totalDueMinor() : null);
            // Ajuster ne crée pas de nouvelle dette : seuls les montants et les mensualités se revérifient.
            $derogations = $this->derogations($debt->employee, $amount, $installment, $terms, $debt->getKey(), $disbursed ? ['installment_amount'] : ['amount', 'installment_amount']);
            $fields = ['amount', 'installment_amount', 'interest_amount', 'first_period', 'repayment_mode'];
            $before = $debt->only($fields);
            $reason = $this->reason($terms['reason'] ?? '');

            $debt->forceFill([
                'amount' => $amount,
                'installment_amount' => $installment,
                ...($disbursed ? [] : $this->interestFields($interest, (bool) $debt->interest_waived)),
                'first_period' => $first->toDateString(),
                'repayment_mode' => $mode,
            ]);

            // ADR-230 — une dette versée qui reprend à un autre mois : le retard se compte
            // depuis la reprise, pas depuis le premier mois d'origine.
            if ($disbursed && ! $keepsPeriod) {
                $debt->schedule_offset = Money::fromMinor($debt->repaidMinor());
            }

            if (! $debt->isDirty()) {
                throw ValidationException::withMessages(['debt' => 'Rien n’a changé.']);
            }

            if ($derogations !== []) {
                $debt->derogations = array_values(array_unique([...($debt->derogations ?? []), ...$derogations]));
            }

            $debt->save();
            $this->auditor->record('staff_debt.adjust', entity: $debt, newValues: [...$debt->only($fields), 'derogations' => $derogations ?: null],
                oldValues: $before, reason: $reason, module: 'finance', actor: $actor);

            return $debt;
        });

        $this->notifier->employee(
            $debt,
            'adjusted',
            'Votre dette '.$debt->number.' a été ajustée',
            'Mensualité de '.StaffDebtNotifier::money($debt->installment_amount).' à partir de '.$debt->first_period->translatedFormat('F Y').', '.mb_strtolower($debt->repayment_mode->label()).'.',
        );

        return $debt;
    }

    /** Annuler un accord tant que rien n'est versé. */
    public function cancel(StaffDebt $debt, string $reason, User $actor): StaffDebt
    {
        $this->authorize($actor, 'staff_debts.decide', 'Seul le DG annule un accord.');

        $debt = DB::transaction(function () use ($debt, $reason, $actor): StaffDebt {
            $debt = $this->lock($debt);

            if ($debt->status !== StaffDebtStatus::Approved) {
                throw ValidationException::withMessages(['debt' => $debt->status === StaffDebtStatus::Active
                    ? 'Cette dette est déjà versée : elle ne s’annule plus, elle se rembourse ou se remet.'
                    : 'Seul un accord pas encore versé s’annule.']);
            }

            $debt->forceFill([
                'status' => StaffDebtStatus::Cancelled,
                'cancel_reason' => $this->reason($reason),
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('cancelled', $actor),
            ])->save();

            return $debt;
        });

        $this->notifier->employee($debt, 'cancelled', 'L’accord de votre dette '.$debt->number.' est annulé', 'Motif : '.$debt->cancel_reason);

        return $debt;
    }

    /** Remettre le reste d'une dette en cours : il n'est plus dû. */
    public function writeOff(StaffDebt $debt, string $reason, User $actor): StaffDebt
    {
        $this->authorize($actor, 'staff_debts.write_off', 'Seul le DG remet une dette.');

        $debt = DB::transaction(function () use ($debt, $reason, $actor): StaffDebt {
            $debt = $this->lock($debt);

            if ($debt->status !== StaffDebtStatus::Active || $debt->balanceMinor() === 0) {
                throw ValidationException::withMessages(['debt' => 'Seule une dette en remboursement, avec un reste dû, se remet.']);
            }

            // ADR-230 — les pénalités encore dues sont remises avec le reste, chacune tracée ;
            // le montant remis ne compte que le montant et son intérêt.
            $reason = $this->reason($reason);
            $this->penalties->waiveAllWithin($debt, 'Remise du reste de la dette : '.$reason, $actor);

            $debt->forceFill([
                'status' => StaffDebtStatus::WrittenOff,
                'written_off_amount' => Money::fromMinor(Money::toMinor((string) $debt->written_off_amount) + $debt->balanceMinor()),
                'write_off_reason' => $reason,
                'written_off_at' => now(),
                'written_off_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('written_off', $actor),
            ])->save();

            return $debt;
        });

        $this->notifier->employee(
            $debt,
            'written_off',
            'Le reste de votre dette '.$debt->number.' vous est remis',
            StaffDebtNotifier::money($debt->written_off_amount).' ne sont plus dus.',
        );

        return $debt;
    }

    /**
     * @param  array<string, mixed>  $terms
     * @param  callable(int): (array<string, mixed>|null)  $interest  l'intérêt d'un montant
     * @return array{0: string, 1: string, 2: \Illuminate\Support\Carbon, 3: StaffDebtRepaymentMode, 4: array<string, mixed>|null}
     */
    private function terms(array $terms, ?Employee $employee, bool $allowPastPeriod = false, ?callable $interest = null, ?int $totalMinor = null): array
    {
        $first = StaffDebtTerms::period($terms['first_period'] ?? null, 'first_period');
        $amountMinor = Money::toMinor((string) $terms['amount']);
        $installmentMinor = Money::toMinor((string) $terms['installment_amount']);
        $resolved = $interest !== null ? $interest($amountMinor) : null;
        $totalMinor ??= $amountMinor + (int) ($resolved['amount_minor'] ?? 0);
        StaffDebtTerms::assert($amountMinor, $installmentMinor, $allowPastPeriod ? null : $first, 'amount', 'installment_amount', 'first_period', $totalMinor);

        $mode = StaffDebtRepaymentMode::tryFrom((string) ($terms['repayment_mode'] ?? ''));
        if ($mode === null) {
            throw ValidationException::withMessages(['repayment_mode' => 'Choisissez le mode de remboursement.']);
        }

        if ($mode === StaffDebtRepaymentMode::Salary && ! ($employee?->remuneration_type?->hasAmount() && (float) $employee->remuneration_amount > 0)) {
            throw ValidationException::withMessages(['repayment_mode' => 'Aucun salaire n’est déclaré pour cette personne (étape Rémunération de son dossier) : la paie n’aurait rien sur quoi retenir. Choisissez les espèces, ou faites déclarer son salaire.']);
        }

        return [Money::fromMinor($amountMinor), Money::fromMinor($installmentMinor), $first, $mode, $resolved];
    }

    /**
     * Les limites du site que ces conditions dépassent : refusées tant que le DG ne
     * confirme pas la dérogation, puis gardées sur la dette.
     *
     * @param  array<string, mixed>  $terms
     * @param  list<string>|null  $only  les limites qui comptent pour ce geste
     * @return list<string>
     */
    private function derogations(?Employee $employee, string $amount, string $installment, array $terms, ?int $ignoreDebtId = null, ?array $only = null): array
    {
        if ($employee === null) {
            return [];
        }

        $violations = $this->rules->violations($employee, Money::toMinor($amount), Money::toMinor($installment), $ignoreDebtId);
        if ($only !== null) {
            $violations = array_intersect_key($violations, array_flip($only));
        }
        if ($violations !== [] && ! ($terms['accept_derogations'] ?? false)) {
            throw ValidationException::withMessages([
                ...$violations,
                'derogation' => 'Ces conditions dépassent les limites du site. Confirmez la dérogation pour accorder quand même : elle restera écrite sur la dette.',
            ]);
        }

        return array_values($violations);
    }

    /**
     * L'intérêt figé sur la dette : sa tranche (mode et valeur) et son montant.
     *
     * @param  array<string, mixed>|null  $interest
     * @return array<string, mixed>
     */
    private function interestFields(?array $interest, bool $waived): array
    {
        return [
            'interest_amount' => Money::fromMinor((int) ($interest['amount_minor'] ?? 0)),
            'interest_mode' => $interest['mode'] ?? null,
            'interest_value' => $interest['value'] ?? null,
            'interest_waived' => $waived,
        ];
    }

    private function lock(StaffDebt $debt): StaffDebt
    {
        return StaffDebt::query()->with('employee')->lockForUpdate()->findOrFail($debt->getKey());
    }

    private function reason(string $reason): string
    {
        $reason = Str::squish($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif est obligatoire.']);
        }

        return $reason;
    }

    private function authorize(User $actor, string $permission, string $message): void
    {
        if ($actor->cannot($permission)) {
            throw new AuthorizationException($message);
        }
    }
}
