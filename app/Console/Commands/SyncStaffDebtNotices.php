<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StaffDebts\StaffDebtWatcher;
use Illuminate\Console\Command;

/**
 * ADR-228 — portail : relit les sites et prévient le DG des demandes de dette du
 * personnel. La cloche le fait déjà à l'ouverture ; cette tâche prévient même quand
 * personne n'a ouvert le portail.
 */
class SyncStaffDebtNotices extends Command
{
    protected $signature = 'rivo:staff-debts:sync';

    protected $description = 'Portail : prévient le DG des demandes de dette du personnel (ADR-228).';

    public function handle(StaffDebtWatcher $watcher): int
    {
        if (config('rivo.site.type') !== 'admin') {
            $this->info('Rien à faire : cette tâche ne tourne que sur le portail.');

            return self::SUCCESS;
        }

        $actor = StaffDebtWatcher::recipients()->first();
        if (! $actor instanceof User) {
            $this->warn('Aucun compte actif du portail n’a le droit « '.StaffDebtWatcher::PERMISSION.' ».');

            return self::SUCCESS;
        }

        $notified = $watcher->sync($actor);
        $this->info($notified > 0 ? "{$notified} demande(s) signalée(s)." : 'Aucune nouvelle demande à signaler.');

        return self::SUCCESS;
    }
}
