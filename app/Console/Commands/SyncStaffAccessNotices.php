<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StaffAccess\StaffAccessWatcher;
use Illuminate\Console\Command;

/**
 * ADR-197 — portail : relit les sites et prévient les Super Admins des employés
 * ajoutés qui attendent leur accès. La cloche le fait déjà à l'ouverture ; cette
 * tâche prévient même quand personne n'a ouvert le portail.
 */
class SyncStaffAccessNotices extends Command
{
    protected $signature = 'rivo:staff-access:sync';

    protected $description = 'Portail : prévient le Super Admin des nouveaux employés sans accès (ADR-197).';

    public function handle(StaffAccessWatcher $watcher): int
    {
        if (config('rivo.site.type') !== 'admin') {
            $this->info('Rien à faire : cette tâche ne tourne que sur le portail.');

            return self::SUCCESS;
        }

        // Les sites revérifient les droits de qui les interroge : un Super Admin actif.
        $actor = StaffAccessWatcher::recipients()->first();
        if (! $actor instanceof User) {
            $this->warn('Aucun compte actif du portail n’a le droit « '.StaffAccessWatcher::PERMISSION.' ».');

            return self::SUCCESS;
        }

        $notified = $watcher->sync($actor);
        $this->info($notified > 0 ? "Notification envoyée pour {$notified} site(s)." : 'Aucun nouvel employé à signaler.');

        return self::SUCCESS;
    }
}
