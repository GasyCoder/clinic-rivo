<?php

namespace App\Services\StaffDebts;

use App\Enums\StaffDebtStatus;
use App\Models\Employee;
use App\Models\StaffDebt;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-230 — une fiche quitte le poste (désactivée ou archivée, par la fiche, l'import ou
 * l'archivage) : ce qui n'a pas encore engagé d'argent se ferme d'office.
 *
 *   demande en attente      close : personne ne décide plus pour une personne partie
 *   accord pas encore versé annulé : rien n'a été remis
 *   dette en remboursement  inchangée : elle devient « à régler au départ » dans Finance,
 *                           et le DG la règle avec la personne (SettleStaffDebtDepartureAction)
 *
 * Rien n'est rouvert si la fiche revient en poste : une nouvelle demande se fait.
 */
final class StaffDebtDeparture
{
    public const REQUEST_CLOSED = 'Demande close d’office : la personne a quitté la clinique.';

    public const APPROVAL_CANCELLED = 'Accord annulé d’office : la personne a quitté la clinique avant le versement.';

    public function __construct(private readonly StaffDebtNotifier $notifier) {}

    public function employeeLeft(Employee $employee): void
    {
        $actor = Auth::user();

        StaffDebt::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [StaffDebtStatus::Requested->value, StaffDebtStatus::Approved->value])
            ->get()
            ->each(function (StaffDebt $debt) use ($actor): void {
                $reason = $debt->status === StaffDebtStatus::Requested ? self::REQUEST_CLOSED : self::APPROVAL_CANCELLED;

                $debt->forceFill([
                    'status' => StaffDebtStatus::Cancelled,
                    'pending_key' => null,
                    'cancel_reason' => $reason,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor?->getKey(),
                    ...RemoteActorAttribution::fields('cancelled', $actor),
                ])->save();

                $this->notifier->employee($debt, 'cancelled', 'Votre dette '.$debt->number.' est close', $reason);
            });
    }
}
