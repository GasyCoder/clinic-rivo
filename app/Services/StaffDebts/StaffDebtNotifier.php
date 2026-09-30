<?php

namespace App\Services\StaffDebts;

use App\Models\StaffDebt;
use App\Models\User;
use App\Notifications\StaffDebtUpdated;
use Illuminate\Support\Facades\Notification;

/**
 * ADR-228 — sur le site, qui apprend quoi d'une dette : l'employé qui l'a demandée
 * (chaque décision, le versement, la fin), et le RH qui la verse (une dette accordée
 * l'attend). Le DG, lui, est prévenu par le portail (StaffDebtWatcher).
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

    public function disbursers(StaffDebt $debt): void
    {
        $recipients = User::query()->where('active', true)->get()->filter(fn (User $user) => $user->can('staff_debts.disburse'))->values();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StaffDebtUpdated(
                'to_disburse',
                $debt->uuid,
                $debt->number,
                "Dette accordée à {$debt->employee_name} : à verser",
                'Dette '.$debt->number.' de '.self::money($debt->amount).' : versez-la hors RIVO, puis marquez-la versée.',
                '/administration/dettes/'.$debt->uuid,
            ));
        }
    }

    public static function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' Ar';
    }
}
