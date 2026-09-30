<?php

namespace App\Actions\Payroll;

use App\Enums\AdvantageEntryStatus;
use App\Enums\BonusAwardStatus;
use App\Enums\SalaryPaymentStatus;
use App\Enums\StaffDebtRepaymentSource;
use App\Models\AdvantageAward;
use App\Models\AdvantageEntry;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Services\Payroll\PayrollBoard;
use App\Services\StaffDebts\StaffDebtLedger;
use App\Services\StaffDebts\StaffDebtNotifier;
use App\Support\Money;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-227 — marquer payée la paie d'un employé pour un mois. Le serveur recompte : salaire
 * de base déclaré + avantages du mois (saisis en attente, déclarés sur la fiche, à l'acte
 * validés). Les lignes et le total sont figés ; les avantages saisis passent « payé » et
 * l'avantage à l'acte « versé » — aucun ne pourra être payé une seconde fois. Le virement
 * se fait hors RIVO. Annuler remet les avantages en attente.
 *
 * ADR-228 — les dettes à retenue sur salaire se retranchent du brut (au plus le brut) :
 * chaque retenue devient un remboursement de la dette, lié à cette paie. Annuler la
 * paie annule ses retenues — la dette redevient due d'autant, et rouverte si elle
 * était soldée.
 */
class PaySalaryAction
{
    public function __construct(
        private readonly PayrollBoard $board,
        private readonly StaffDebtLedger $ledger,
        private readonly StaffDebtNotifier $notifier,
    ) {}

    public function execute(Employee $employee, Carbon $month, ?string $note, User $actor): SalaryPayment
    {
        if ($actor->cannot('salary_payments.pay')) {
            throw new AuthorizationException('Vous ne pouvez pas marquer une paie payée.');
        }

        $month = $month->copy()->startOfMonth();

        if ($month->isAfter(now()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Un mois à venir ne se paie pas encore.']);
        }

        [$payment, $settled] = DB::transaction(function () use ($employee, $month, $note, $actor): array {
            $activeKey = SalaryPayment::activeKey($employee->getKey(), $month->format('Y-m'));

            if (SalaryPayment::query()->where('active_key', $activeKey)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['period' => 'La paie de ce mois est déjà marquée payée pour cette personne.']);
            }

            $entries = AdvantageEntry::query()->where('employee_id', $employee->getKey())
                ->whereDate('period', $month->toDateString())->where('status', AdvantageEntryStatus::Pending)
                ->lockForUpdate()->orderBy('id')->get();
            $award = AdvantageAward::query()->where('employee_id', $employee->getKey())
                ->whereDate('period', $month->toDateString())->where('status', BonusAwardStatus::Validated)
                ->lockForUpdate()->first();
            $lines = $this->board->lines($employee, $entries, $this->board->declaredBenefits($month, $employee->getKey()), $award);
            $debts = $this->board->salaryDebts($employee->getKey(), lock: true);
            $deductions = $this->ledger->salaryDeductions($debts, $month, Money::toMinor(number_format(PayrollBoard::grossOf($lines), 2, '.', '')));
            foreach ($deductions as $deduction) {
                $lines[] = $this->ledger->payrollLine($deduction['debt'], $deduction['amount_minor'], $deduction['due_minor']);
            }

            $base = array_sum(array_map(fn ($line) => $line['kind'] === 'BASE' ? (float) $line['amount'] : 0, $lines));
            $total = PayrollBoard::grossOf($lines);
            $deductionsTotal = PayrollBoard::deductionsOf($lines);

            if ($total <= 0) {
                throw ValidationException::withMessages(['period' => 'Rien à payer ce mois-ci pour cette personne : ni salaire déclaré, ni avantage.']);
            }

            $note = filled($note) ? Str::squish($note) : null;
            $payment = SalaryPayment::query()->create([
                'employee_id' => $employee->getKey(),
                'employee_name' => Str::squish("{$employee->last_name} {$employee->first_name}"),
                'period' => $month->toDateString(),
                'remuneration_type' => $employee->remuneration_type?->value,
                'base_amount' => number_format($base, 2, '.', ''),
                'advantages_amount' => number_format($total - $base, 2, '.', ''),
                'deductions_amount' => number_format($deductionsTotal, 2, '.', ''),
                'total_amount' => number_format($total, 2, '.', ''),
                'lines' => $lines,
                'advantage_award_id' => $award?->getKey(),
                'status' => SalaryPaymentStatus::Paid,
                'active_key' => $activeKey,
                'paid_at' => now(),
                'paid_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('paid', $actor),
                'payment_note' => $note,
            ]);

            foreach ($entries as $entry) {
                $entry->forceFill(['status' => AdvantageEntryStatus::Paid, 'salary_payment_id' => $payment->getKey()])->save();
            }

            $award?->forceFill([
                'status' => BonusAwardStatus::Paid, 'paid_at' => now(), 'paid_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('paid', $actor),
                'payment_note' => 'Payé avec la paie de '.$month->translatedFormat('F Y').'.',
            ])->save();

            $settled = [];
            foreach ($deductions as $deduction) {
                /** @var StaffDebt $debt */
                $debt = $deduction['debt'];
                StaffDebtRepayment::query()->create([
                    'staff_debt_id' => $debt->getKey(),
                    'employee_id' => $employee->getKey(),
                    'source' => StaffDebtRepaymentSource::Salary,
                    'period' => $month->toDateString(),
                    'amount' => Money::fromMinor($deduction['amount_minor']),
                    'salary_payment_id' => $payment->getKey(),
                    'note' => $deduction['amount_minor'] < $deduction['due_minor'] ? 'Retenue partielle : le brut du mois ne couvrait pas la mensualité.' : null,
                    'recorded_at' => now(),
                    'recorded_by' => $actor->getKey(),
                    ...RemoteActorAttribution::fields('recorded', $actor),
                ]);

                if ($this->ledger->refreshStatus($debt)) {
                    $settled[] = $debt;
                }
            }

            return [$payment, $settled];
        });

        foreach ($settled as $debt) {
            $this->notifier->employee($debt, 'settled', 'Votre dette '.$debt->number.' est soldée', 'La dernière retenue a été faite sur la paie de '.$month->translatedFormat('F Y').'.');
        }

        return $payment;
    }

    public function cancel(SalaryPayment $payment, string $reason, User $actor): SalaryPayment
    {
        if ($actor->cannot('salary_payments.cancel')) {
            throw new AuthorizationException('Vous ne pouvez pas annuler une paie.');
        }

        return DB::transaction(function () use ($payment, $reason, $actor): SalaryPayment {
            $payment = SalaryPayment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($payment->status !== SalaryPaymentStatus::Paid) {
                throw ValidationException::withMessages(['payment' => 'Cette paie est déjà annulée.']);
            }

            $payment->forceFill([
                'status' => SalaryPaymentStatus::Cancelled, 'active_key' => null, 'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(), ...RemoteActorAttribution::fields('cancelled', $actor),
                'cancel_reason' => Str::squish($reason),
            ])->save();

            AdvantageEntry::withTrashed()->where('salary_payment_id', $payment->getKey())->lockForUpdate()->get()
                ->each(fn (AdvantageEntry $entry) => $entry->forceFill(['status' => AdvantageEntryStatus::Pending, 'salary_payment_id' => null])->save());

            if ($payment->advantage_award_id !== null) {
                $award = AdvantageAward::query()->lockForUpdate()->find($payment->advantage_award_id);
                $award?->forceFill([
                    'status' => BonusAwardStatus::Validated, 'paid_at' => null, 'paid_by' => null,
                    'external_paid_by_uuid' => null, 'external_paid_by_name' => null, 'payment_note' => null,
                ])->save();
            }

            // ADR-228 — les retenues de cette paie n'ont pas eu lieu : la dette redevient due d'autant.
            StaffDebtRepayment::query()->where('salary_payment_id', $payment->getKey())->whereNull('reversed_at')->lockForUpdate()->get()
                ->each(function (StaffDebtRepayment $repayment) use ($actor, $payment): void {
                    $repayment->forceFill([
                        'reversed_at' => now(), 'reversed_by' => $actor->getKey(),
                        ...RemoteActorAttribution::fields('reversed', $actor),
                        'reverse_reason' => 'Paie annulée : '.$payment->cancel_reason,
                    ])->save();
                    $this->ledger->refreshStatus(StaffDebt::query()->lockForUpdate()->findOrFail($repayment->staff_debt_id));
                });

            return $payment;
        });
    }
}
