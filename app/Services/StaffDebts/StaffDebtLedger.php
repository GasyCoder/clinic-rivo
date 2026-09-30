<?php

namespace App\Services\StaffDebts;

use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtRepaymentSource;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ADR-228 — les comptes d'une dette du personnel, écrits une seule fois : son plan de
 * remboursement, ce qu'elle retient sur la paie d'un mois, ce qui est en retard.
 *
 * Aucune mensualité n'est stockée : l'échéancier se lit sur le reste dû. Chaque mois, la
 * paie retient au plus une mensualité (jamais plus que le reste dû, jamais plus que le
 * salaire du mois) ; ce qui n'a pas pu être retenu reste dû et allonge le remboursement
 * d'autant — le « report sur une mensualité supplémentaire » arrêté par le propriétaire.
 */
final class StaffDebtLedger
{
    /**
     * Le plan d'un montant : combien de mensualités, la dernière (plus petite si le
     * montant ne tombe pas juste), et le mois de la dernière. ADR-229 — le montant est
     * ce qui est à rembourser : le montant emprunté et son intérêt.
     *
     * @return array{count: int, installment: string, last_amount: string, first_period: string, last_period: string}|null
     */
    public static function plan(int $amountMinor, int $installmentMinor, Carbon $firstPeriod): ?array
    {
        if ($amountMinor <= 0 || $installmentMinor <= 0) {
            return null;
        }

        $installmentMinor = min($installmentMinor, $amountMinor);
        $count = intdiv($amountMinor + $installmentMinor - 1, $installmentMinor);
        $first = $firstPeriod->copy()->startOfMonth();

        return [
            'count' => $count,
            'installment' => Money::fromMinor($installmentMinor),
            'last_amount' => Money::fromMinor($amountMinor - ($count - 1) * $installmentMinor),
            'first_period' => $first->format('Y-m'),
            'last_period' => $first->copy()->addMonthsNoOverflow($count - 1)->format('Y-m'),
        ];
    }

    /**
     * Ce que la paie d'un employé retient pour un mois : ses dettes à retenue sur salaire,
     * la plus ancienne d'abord, sans jamais dépasser le salaire du mois (le net n'est
     * jamais négatif).
     *
     * @param  Collection<int, StaffDebt>  $debts  les dettes de cet employé, remboursements chargés
     * @return list<array{debt: StaffDebt, amount_minor: int, due_minor: int}>
     */
    public function salaryDeductions(Collection $debts, Carbon $month, int $grossMinor): array
    {
        $month = $month->copy()->startOfMonth();
        $capacity = max(0, $grossMinor);
        $deductions = [];

        foreach ($debts->sortBy('id') as $debt) {
            if ($capacity <= 0) {
                break;
            }

            $due = $this->dueInSalary($debt, $month);
            if ($due <= 0) {
                continue;
            }

            $amount = min($due, $capacity);
            $capacity -= $amount;
            $deductions[] = ['debt' => $debt, 'amount_minor' => $amount, 'due_minor' => $due];
        }

        return $deductions;
    }

    /**
     * La mensualité qu'une dette attend de la paie de ce mois : une dette versée, à
     * retenue sur salaire, dont le premier mois est atteint et qu'aucune paie de ce
     * mois n'a déjà retenue.
     */
    public function dueInSalary(StaffDebt $debt, Carbon $month): int
    {
        $month = $month->copy()->startOfMonth();

        if ($debt->status !== StaffDebtStatus::Active || $debt->repayment_mode !== StaffDebtRepaymentMode::Salary) {
            return 0;
        }

        if ($debt->first_period === null || $debt->first_period->copy()->startOfMonth()->gt($month)) {
            return 0;
        }

        if ($debt->disbursed_on === null || $debt->disbursed_on->copy()->startOfDay()->gt($month->copy()->endOfMonth())) {
            return 0;
        }

        $alreadyRetained = $this->repayments($debt)->contains(fn (StaffDebtRepayment $repayment) => $repayment->source === StaffDebtRepaymentSource::Salary
            && $repayment->period->format('Y-m') === $month->format('Y-m'));

        if ($alreadyRetained) {
            return 0;
        }

        return min(Money::toMinor((string) $debt->installment_amount), $debt->balanceMinor());
    }

    /**
     * La ligne de paie d'une retenue : son montant est négatif, il se retranche du brut.
     *
     * @return array{kind: string, label: string, amount: string, uuid: string}
     */
    public function payrollLine(StaffDebt $debt, int $amountMinor, int $dueMinor): array
    {
        $label = 'Remboursement de la dette '.$debt->number;
        if ($amountMinor < $dueMinor) {
            $label .= ' — partiel, le salaire du mois ne couvre pas la mensualité : le reste est reporté';
        }

        return ['kind' => 'DEBT', 'label' => $label, 'amount' => Money::fromMinor(-$amountMinor), 'uuid' => $debt->uuid];
    }

    /** Le prochain mois où un remboursement est attendu ; null pour une dette qui n'en attend plus. */
    public function nextPeriod(StaffDebt $debt, Carbon $today): ?Carbon
    {
        if (! in_array($debt->status, [StaffDebtStatus::Requested, StaffDebtStatus::Approved, StaffDebtStatus::Active], true)) {
            return null;
        }

        $first = ($debt->first_period ?? $debt->requested_first_period)->copy()->startOfMonth();
        $current = $today->copy()->startOfMonth();
        $next = $first->gt($current) ? $first : $current;

        if ($debt->status === StaffDebtStatus::Active) {
            if ($debt->balanceMinor() === 0) {
                return null;
            }

            // La mensualité de ce mois est déjà remboursée : on attend la suivante.
            if ($this->repaidInMonthMinor($debt, $next) >= Money::toMinor((string) $debt->installment_amount)) {
                $next = $next->copy()->addMonthNoOverflow();
            }
        }

        return $next;
    }

    /**
     * Les mois à venir et leur montant : le plan demandé ou accordé tant que rien n'est
     * versé, puis le reste dû réparti en mensualités depuis le prochain mois attendu.
     *
     * @return list<array{period: string, amount: string}>
     */
    public function projection(StaffDebt $debt, Carbon $today): array
    {
        $next = $this->nextPeriod($debt, $today);
        if ($next === null) {
            return [];
        }

        [$amount, $installment] = match ($debt->status) {
            StaffDebtStatus::Requested => [$debt->requestedTotalMinor(), Money::toMinor((string) $debt->requested_installment)],
            StaffDebtStatus::Approved => [$debt->totalDueMinor(), Money::toMinor((string) $debt->installment_amount)],
            default => [$debt->balanceMinor(), Money::toMinor((string) $debt->installment_amount)],
        };

        if ($amount <= 0 || $installment <= 0) {
            return [];
        }

        $months = [];
        $period = $next->copy();
        // Ce qui a déjà été remboursé ce mois-ci réduit la mensualité du mois.
        $firstDue = $debt->status === StaffDebtStatus::Active
            ? max(0, $installment - $this->repaidInMonthMinor($debt, $period))
            : $installment;
        $due = $firstDue > 0 ? $firstDue : $installment;

        // Borné : une mensualité minuscule ne doit pas produire des siècles de lignes.
        while ($amount > 0 && count($months) < 240) {
            $take = min($due, $amount);
            $months[] = ['period' => $period->format('Y-m'), 'amount' => Money::fromMinor($take)];
            $amount -= $take;
            $period = $period->copy()->addMonthNoOverflow();
            $due = $installment;
        }

        return $months;
    }

    /**
     * Le retard d'une dette remboursée en espèces : ce qui devait être remis depuis le
     * premier mois, moins ce qui l'a été. Une retenue sur salaire n'a pas de retard :
     * c'est la paie qui retient, mois après mois.
     *
     * ADR-230 — quand l'échéancier reprend (règlement au départ, nouveau mois de reprise),
     * le retard se compte depuis cette reprise : `schedule_offset` est ce qui était déjà
     * remboursé à ce moment-là. Les pénalités non remises se remboursent à la suite du
     * montant et de son intérêt.
     */
    public function arrearsMinor(StaffDebt $debt, Carbon $today): int
    {
        if ($debt->status !== StaffDebtStatus::Active || $debt->repayment_mode !== StaffDebtRepaymentMode::Cash || $debt->first_period === null) {
            return 0;
        }

        $months = self::monthsThrough($debt->first_period, $today);
        if ($months <= 0) {
            return 0;
        }

        $offset = Money::toMinor((string) ($debt->schedule_offset ?? 0));
        $owed = max(0, $debt->principalOwedMinor() + $debt->penaltiesMinor() - $offset);
        $expected = min($owed, $months * Money::toMinor((string) $debt->installment_amount));

        return max(0, $expected - ($debt->repaidMinor() - $offset));
    }

    /**
     * ADR-230 — le montant encore en retard pour les mensualités jusqu'au mois `$period`
     * inclus, compte tenu des remboursements faits avant `$at` (la fin du délai de grâce).
     * Seuls le montant et son intérêt comptent : un remboursement les paie d'abord, et une
     * pénalité ne porte jamais sur une pénalité.
     */
    public function overdueForPenaltyMinor(StaffDebt $debt, Carbon $period, Carbon $at): int
    {
        if ($debt->first_period === null) {
            return 0;
        }

        $months = self::monthsThrough($debt->first_period, $period);
        if ($months <= 0) {
            return 0;
        }

        $offset = Money::toMinor((string) ($debt->schedule_offset ?? 0));
        $expected = min(max(0, $debt->principalOwedMinor() - $offset), $months * Money::toMinor((string) $debt->installment_amount));
        $repaid = (int) $this->repayments($debt)
            ->filter(fn (StaffDebtRepayment $repayment) => $repayment->recorded_at !== null && $repayment->recorded_at->lt($at))
            ->sum(fn (StaffDebtRepayment $repayment) => Money::toMinor((string) $repayment->amount));

        return max(0, $expected - ($repaid - $offset));
    }

    /** Le nombre de mois de `$first` à `$month` inclus ; zéro (ou moins) avant le premier mois. */
    public static function monthsThrough(Carbon $first, Carbon $month): int
    {
        $first = $first->copy()->startOfMonth();
        $month = $month->copy()->startOfMonth();

        return ((int) $month->format('Y') - (int) $first->format('Y')) * 12 + ((int) $month->format('n') - (int) $first->format('n')) + 1;
    }

    /** Ce qui a été remboursé dans un mois donné, toutes sources confondues. */
    public function repaidInMonthMinor(StaffDebt $debt, Carbon $month): int
    {
        return (int) $this->repayments($debt)
            ->filter(fn (StaffDebtRepayment $repayment) => $repayment->period->format('Y-m') === $month->format('Y-m'))
            ->sum(fn (StaffDebtRepayment $repayment) => Money::toMinor((string) $repayment->amount));
    }

    /**
     * Soldée quand plus rien n'est dû, rouverte quand un remboursement est annulé.
     * À appeler dans la transaction qui a changé ses remboursements.
     *
     * @return bool vrai si le statut a changé
     */
    public function refreshStatus(StaffDebt $debt): bool
    {
        $debt->unsetRelation('repayments');
        $balance = $debt->balanceMinor();

        if ($debt->status === StaffDebtStatus::Active && $balance === 0) {
            $debt->forceFill(['status' => StaffDebtStatus::Settled, 'settled_at' => now()])->save();

            return true;
        }

        if ($debt->status === StaffDebtStatus::Settled && $balance > 0) {
            $debt->forceFill(['status' => StaffDebtStatus::Active, 'settled_at' => null])->save();

            return true;
        }

        // Remise puis remboursement annulé (paie annulée) : la remise couvrait ce qui restait
        // dû ce jour-là, pas une retenue qui n'a finalement pas eu lieu — elle redevient due.
        if ($debt->status === StaffDebtStatus::WrittenOff && $balance > 0) {
            $debt->forceFill(['status' => StaffDebtStatus::Active])->save();

            return true;
        }

        return false;
    }

    /** @return Collection<int, StaffDebtRepayment> les remboursements non annulés */
    private function repayments(StaffDebt $debt): Collection
    {
        return ($debt->relationLoaded('repayments') ? $debt->repayments : $debt->repayments()->get())
            ->whereNull('reversed_at')
            ->values();
    }
}
