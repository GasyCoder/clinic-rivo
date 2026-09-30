<?php

namespace App\Services\StaffDebts;

use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
use App\Models\StaffDebtPenalty;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-230 — les pénalités de retard des dettes du personnel, liquidées une fois par mois :
 *
 *   - seulement pour un remboursement en espèces : une retenue sur salaire n'est jamais en
 *     retard par la faute de l'employé (la paie retient, ou le salaire ne suffit pas) ;
 *   - seulement la règle figée sur la dette à son accord, jamais celle du jour ;
 *   - pour le mois M, à la fin du délai de grâce (le 1er du mois suivant + N jours) : le
 *     taux mensuel appliqué au seul montant encore en retard à ce moment-là, arrondi à
 *     l'ariary ; un remboursement fait pendant le délai de grâce l'évite ;
 *   - jamais sur une pénalité (un remboursement paie d'abord le montant et son intérêt) ;
 *   - plafonnées en tout à un pourcentage du montant emprunté ;
 *   - une par mois au plus, jamais supprimée ; le DG la remet avec un motif.
 *
 * Tâche planifiée du site (`rivo:staff-debts:penalties`) : elle rattrape les mois qu'elle
 * n'aurait pas vus, sans jamais liquider deux fois le même.
 */
final class StaffDebtPenalties
{
    public function __construct(
        private readonly StaffDebtLedger $ledger,
        private readonly StaffDebtNotifier $notifier,
    ) {}

    /** @return int les pénalités liquidées */
    public function assessAll(?Carbon $today = null): int
    {
        $today ??= now();
        $count = 0;

        StaffDebt::query()
            ->where('status', StaffDebtStatus::Active->value)
            ->where('repayment_mode', StaffDebtRepaymentMode::Cash->value)
            ->whereNotNull('penalty_rate')
            ->pluck('id')
            ->each(function (int $id) use ($today, &$count): void {
                $count += count($this->assess(StaffDebt::query()->findOrFail($id), $today));
            });

        return $count;
    }

    /**
     * Les pénalités dues à ce jour sur une dette, liquidées si elles ne l'étaient pas.
     *
     * @return list<StaffDebtPenalty> celles qui viennent d'être liquidées
     */
    public function assess(StaffDebt $debt, ?Carbon $today = null): array
    {
        $today ??= now();

        $created = DB::transaction(function () use ($debt, $today): array {
            $debt = StaffDebt::query()->with(['repayments', 'penalties', 'employee'])->lockForUpdate()->findOrFail($debt->getKey());
            $rule = $debt->penaltyRule();

            if ($rule === null || $debt->status !== StaffDebtStatus::Active || $debt->repayment_mode !== StaffDebtRepaymentMode::Cash || $debt->first_period === null) {
                return [];
            }

            $done = $debt->penalties->map(fn (StaffDebtPenalty $penalty) => $penalty->period->format('Y-m'))->all();
            $charged = $debt->penaltiesMinor();
            $cap = $rule['cap_rate'] !== null ? Money::percentage(Money::toMinor((string) $debt->amount), $rule['cap_rate']) : null;
            $current = $today->copy()->startOfMonth();
            $created = [];

            for ($period = $debt->first_period->copy()->startOfMonth(); $period->lt($current); $period = $period->copy()->addMonthNoOverflow()) {
                $assessOn = self::assessmentDate($period, $rule['grace_days']);
                if ($assessOn->gt($today)) {
                    break;
                }

                if (in_array($period->format('Y-m'), $done, true)) {
                    continue;
                }

                if ($cap !== null && $charged >= $cap) {
                    break;
                }

                $base = $this->ledger->overdueForPenaltyMinor($debt, $period, $assessOn);
                if ($base <= 0) {
                    continue;
                }

                $amount = intdiv(Money::percentage($base, $rule['rate']) + 50, 100) * 100;
                if ($cap !== null) {
                    $amount = min($amount, $cap - $charged);
                }
                if ($amount <= 0) {
                    continue;
                }

                $created[] = $debt->penalties()->create([
                    'period' => $period->toDateString(),
                    'base_amount' => Money::fromMinor($base),
                    'rate' => $rule['rate'],
                    'amount' => Money::fromMinor($amount),
                    'assessed_at' => now(),
                ]);
                $charged += $amount;
            }

            return $created;
        });

        if ($created !== []) {
            $debt->refresh();
            $total = array_sum(array_map(fn (StaffDebtPenalty $penalty) => Money::toMinor((string) $penalty->amount), $created));
            $this->notifier->employee(
                $debt,
                'penalty',
                'Pénalité de retard sur votre dette '.$debt->number,
                StaffDebtNotifier::money(Money::fromMinor($total)).' de pénalité ('.self::rate($created[0]->rate).' % par mois du montant en retard). '
                    .'Reste dû : '.StaffDebtNotifier::money(Money::fromMinor($debt->balanceMinor())).', à remettre à la Caisse.',
            );
        }

        return $created;
    }

    /** Le jour où la pénalité du mois `$period` se liquide : le 1er du mois suivant, plus le délai de grâce. */
    public static function assessmentDate(Carbon $period, int $graceDays): Carbon
    {
        return $period->copy()->startOfMonth()->addMonthNoOverflow()->startOfDay()->addDays(max(0, $graceDays));
    }

    /** Le DG remet une pénalité : elle n'est plus due, elle reste écrite. */
    public function waive(StaffDebt $debt, StaffDebtPenalty $penalty, string $reason, User $actor): StaffDebtPenalty
    {
        if ($actor->cannot('staff_debts.write_off')) {
            throw new AuthorizationException('Seul le DG remet une pénalité.');
        }

        $reason = $this->reason($reason);

        $penalty = DB::transaction(function () use ($debt, $penalty, $reason, $actor): StaffDebtPenalty {
            $debt = StaffDebt::query()->lockForUpdate()->findOrFail($debt->getKey());
            $penalty = StaffDebtPenalty::query()->where('staff_debt_id', $debt->getKey())->lockForUpdate()->findOrFail($penalty->getKey());

            if ($penalty->waived_at !== null) {
                throw ValidationException::withMessages(['penalty' => 'Cette pénalité est déjà remise.']);
            }

            $this->markWaived($penalty, $reason, $actor);
            $this->ledger->refreshStatus($debt);

            return $penalty;
        });

        $this->notifier->employee(
            $debt->refresh(),
            'penalty_waived',
            'Une pénalité de retard vous est remise',
            StaffDebtNotifier::money($penalty->amount).' ne sont plus dus sur la dette '.$debt->number.'.',
        );

        return $penalty;
    }

    /**
     * Remettre toutes les pénalités encore dues d'une dette, dans la transaction de
     * l'appelant (remise du reste, règlement au départ).
     *
     * @return int le montant remis, en unités mineures
     */
    public function waiveAllWithin(StaffDebt $debt, string $reason, User $actor): int
    {
        $total = 0;

        StaffDebtPenalty::query()->where('staff_debt_id', $debt->getKey())->whereNull('waived_at')->lockForUpdate()->get()
            ->each(function (StaffDebtPenalty $penalty) use ($reason, $actor, &$total): void {
                $this->markWaived($penalty, $reason, $actor);
                $total += Money::toMinor((string) $penalty->amount);
            });

        $debt->unsetRelation('penalties');

        return $total;
    }

    /** « 2.00 » → « 2 », « 1.50 » → « 1,5 ». */
    public static function rate(mixed $rate): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.'));
    }

    private function markWaived(StaffDebtPenalty $penalty, string $reason, User $actor): void
    {
        $penalty->forceFill([
            'waived_at' => now(),
            'waived_by' => $actor->getKey(),
            'waiver_reason' => $reason,
            ...RemoteActorAttribution::fields('waived', $actor),
        ])->save();
    }

    private function reason(string $reason): string
    {
        $reason = Str::squish($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Le motif est obligatoire.']);
        }

        return $reason;
    }
}
