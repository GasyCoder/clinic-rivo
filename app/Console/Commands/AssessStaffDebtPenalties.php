<?php

namespace App\Console\Commands;

use App\Services\StaffDebts\StaffDebtPenalties;
use Illuminate\Console\Command;

/**
 * ADR-230 — sur le site : liquide les pénalités de retard des dettes du personnel
 * remboursées en espèces, une fois par mois et par dette, après le délai de grâce.
 * Rattrape les mois manqués : une tâche arrêtée quelques jours ne perd rien, et une
 * pénalité déjà liquidée pour un mois ne l'est jamais deux fois.
 */
class AssessStaffDebtPenalties extends Command
{
    protected $signature = 'rivo:staff-debts:penalties';

    protected $description = 'Site : liquide les pénalités de retard des dettes du personnel (ADR-230).';

    public function handle(StaffDebtPenalties $penalties): int
    {
        if (config('rivo.site.type') === 'admin') {
            $this->info('Rien à faire : cette tâche ne tourne que sur un site.');

            return self::SUCCESS;
        }

        $count = $penalties->assessAll();
        $this->info($count > 0 ? "{$count} pénalité(s) liquidée(s)." : 'Aucune pénalité à liquider.');

        return self::SUCCESS;
    }
}
