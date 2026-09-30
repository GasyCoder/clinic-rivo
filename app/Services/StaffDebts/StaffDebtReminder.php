<?php

namespace App\Services\StaffDebts;

use App\Enums\StaffDebtRepaymentMode;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ADR-229 — la relance des remboursements en espèces en retard : l'employé et le RH du
 * site sont prévenus. Automatique une fois par mois et par dette (tâche planifiée du
 * site, `arrears_notified_for`), ou à la main par le DG depuis le portail.
 */
final class StaffDebtReminder
{
    public function __construct(
        private readonly StaffDebtLedger $ledger,
        private readonly StaffDebtNotifier $notifier,
        private readonly Auditor $auditor,
    ) {}

    /** @return int les dettes relancées */
    public function remindAll(?Carbon $today = null): int
    {
        $today ??= now();
        $month = $today->format('Y-m');
        $reminded = 0;

        StaffDebt::query()
            ->where('status', StaffDebtStatus::Active->value)
            ->where('repayment_mode', StaffDebtRepaymentMode::Cash->value)
            ->where(fn ($query) => $query->whereNull('arrears_notified_for')->orWhere('arrears_notified_for', '!=', $month))
            ->with(['repayments', 'employee'])
            ->get()
            ->each(function (StaffDebt $debt) use ($today, $month, &$reminded): void {
                $arrears = $this->ledger->arrearsMinor($debt, $today);
                if ($arrears <= 0) {
                    return;
                }

                $this->notifier->arrears($debt, Money::fromMinor($arrears));
                $debt->forceFill(['arrears_notified_for' => $month])->saveQuietly();
                $reminded++;
            });

        return $reminded;
    }

    /** La relance du DG, même si la dette a déjà été relancée ce mois-ci. */
    public function remind(StaffDebt $debt, User $actor): string
    {
        if ($actor->cannot('staff_debts.decide')) {
            throw new AuthorizationException('Seul le DG relance un remboursement en retard.');
        }

        $debt->loadMissing(['repayments', 'employee']);
        $arrears = $this->ledger->arrearsMinor($debt, now());
        if ($arrears <= 0) {
            throw ValidationException::withMessages(['debt' => 'Aucun remboursement en retard sur cette dette.']);
        }

        $amount = Money::fromMinor($arrears);
        $this->notifier->arrears($debt, $amount);
        $debt->forceFill(['arrears_notified_for' => now()->format('Y-m')])->saveQuietly();
        $this->auditor->record('staff_debt.remind', entity: $debt, newValues: ['arrears' => $amount], module: 'finance', actor: $actor);

        return $amount;
    }
}
