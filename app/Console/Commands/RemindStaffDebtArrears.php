<?php

namespace App\Console\Commands;

use App\Services\StaffDebts\StaffDebtReminder;
use Illuminate\Console\Command;

/**
 * ADR-229 — sur le site : relance, une fois par mois et par dette, les remboursements en
 * espèces en retard (l'employé et le RH en sont prévenus).
 */
class RemindStaffDebtArrears extends Command
{
    protected $signature = 'rivo:staff-debts:remind';

    protected $description = 'Site : relance les remboursements de dettes du personnel en retard (ADR-229).';

    public function handle(StaffDebtReminder $reminder): int
    {
        if (config('rivo.site.type') === 'admin') {
            $this->info('Rien à faire : cette tâche ne tourne que sur un site.');

            return self::SUCCESS;
        }

        $count = $reminder->remindAll();
        $this->info($count > 0 ? "{$count} dette(s) relancée(s)." : 'Aucun retard à relancer.');

        return self::SUCCESS;
    }
}
