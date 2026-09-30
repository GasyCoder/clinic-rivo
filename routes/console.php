<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-197 — l'accès du personnel : sur le portail, prévenir le Super Admin des
// nouveaux employés. Sans effet sur un site. (Aucun mot de passe à effacer depuis
// l'ADR-202 : l'employé choisit le sien à sa première connexion.)
Schedule::command('rivo:staff-access:sync')->everyFiveMinutes()->withoutOverlapping();
// ADR-228 — le portail prévient le DG des demandes de dette du personnel.
Schedule::command('rivo:staff-debts:sync')->everyFiveMinutes()->withoutOverlapping();
// ADR-229 — sur un site : relance mensuelle des remboursements en espèces en retard.
Schedule::command('rivo:staff-debts:remind')->dailyAt('08:00')->withoutOverlapping();
