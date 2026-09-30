<?php

namespace App\Actions\Payroll;

use App\Enums\AdvantageEntryStatus;
use App\Enums\BonusAwardStatus;
use App\Enums\SalaryPaymentStatus;
use App\Models\AdvantageAward;
use App\Models\AdvantageEntry;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\Payroll\PayrollBoard;
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
 */
class PaySalaryAction
{
    public function __construct(private readonly PayrollBoard $board) {}

    public function execute(Employee $employee, Carbon $month, ?string $note, User $actor): SalaryPayment
    {
        if ($actor->cannot('salary_payments.pay')) {
            throw new AuthorizationException('Vous ne pouvez pas marquer une paie payée.');
        }

        $month = $month->copy()->startOfMonth();

        if ($month->isAfter(now()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Un mois à venir ne se paie pas encore.']);
        }

        return DB::transaction(function () use ($employee, $month, $note, $actor): SalaryPayment {
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

            $base = array_sum(array_map(fn ($line) => $line['kind'] === 'BASE' ? (float) $line['amount'] : 0, $lines));
            $total = array_sum(array_map(fn ($line) => (float) $line['amount'], $lines));

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

            return $payment;
        });
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

            return $payment;
        });
    }
}
