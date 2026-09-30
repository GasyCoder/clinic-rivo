<?php

namespace App\Services\StaffDebts;

use App\Models\StaffDebt;
use App\Models\User;
use App\Notifications\StaffDebtUpdated;
use Illuminate\Support\Facades\Notification;

/**
 * ADR-228 — sur le site, qui apprend quoi d'une dette : l'employé qui l'a demandée
 * (chaque décision, le versement, la fin). Le DG, lui, est prévenu par le portail
 * (StaffDebtWatcher). ADR-229 — tout se décide et se verse au portail : le RH n'est plus
 * prévenu d'une dette à verser, seulement des remboursements en retard.
 */
final class StaffDebtNotifier
{
    public function employee(StaffDebt $debt, string $kind, string $title, string $body): void
    {
        $user = $debt->employee?->user_id !== null ? User::query()->where('active', true)->find($debt->employee->user_id) : null;

        if ($user !== null) {
            $user->notify(new StaffDebtUpdated($kind, $debt->uuid, $debt->number, $title, $body, '/mes-dettes?dette='.$debt->uuid));
        }
    }

    /**
     * ADR-229 — un remboursement en espèces en retard : l'employé et le RH du site (qui
     * voit les dettes du personnel) sont prévenus. La Caisse voit déjà le retard.
     */
    public function arrears(StaffDebt $debt, string $arrears): void
    {
        $body = StaffDebtNotifier::money($arrears).' en retard sur la dette '.$debt->number.' : à remettre à la Caisse, qui délivre un reçu.';

        $this->employee($debt, 'late', 'Remboursement en retard', $body);

        $employeeUserId = $debt->employee?->user_id;
        $recipients = User::query()->where('active', true)->get()
            ->filter(fn (User $user) => $user->getKey() !== $employeeUserId && $user->can('staff_debts.view'))
            ->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StaffDebtUpdated(
                'late',
                $debt->uuid,
                $debt->number,
                "Dette de {$debt->employee_name} : remboursement en retard",
                StaffDebtNotifier::money($arrears).' en retard sur la dette '.$debt->number.', remboursée en espèces à la Caisse.',
                null,
            ));
        }
    }

    public static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' Ar';
    }
}
