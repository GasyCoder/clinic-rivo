<?php

namespace App\Notifications;

use Illuminate\Support\Carbon;

/**
 * ADR-202 — au RH : un employé s'est connecté pour la première fois et a choisi
 * son mot de passe. C'est aussi ce qui permet de voir une première connexion que
 * l'employé n'aurait pas faite lui-même.
 */
class StaffAccessActivated extends InboxNotification
{
    public function __construct(
        public readonly string $name,
        public readonly Carbon $at,
        public readonly ?string $handoverUuid,
        public readonly bool $mailboxReady,
    ) {}

    public function payload(): array
    {
        return [
            'kind' => 'staff_access.activated',
            'category' => 'staff_access',
            'title' => "{$this->name} a activé son compte",
            'body' => 'Première connexion le '.$this->at->format('d/m/Y à H:i').' : mot de passe choisi par l’employé.'
                .($this->mailboxReady ? '' : ' Sa messagerie n’a pas reçu ce mot de passe : voyez avec le Super Admin.')
                .' Si ce n’était pas lui, prévenez le Super Admin.',
            'url' => $this->handoverUuid ? '/administration/staff-access/'.$this->handoverUuid : '/administration/staff-access',
            'icon' => 'user-check',
            'tone' => $this->mailboxReady ? 'success' : 'warning',
        ];
    }
}
