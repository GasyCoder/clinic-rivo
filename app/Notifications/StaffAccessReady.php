<?php

namespace App\Notifications;

/**
 * ADR-197 / ADR-202 — site : le Super Admin a créé des accès pour le personnel et
 * les annonce au RH, qui dit à chaque employé que son compte existe et où se
 * connecter. Aucun mot de passe : l'employé choisit le sien à sa première connexion.
 */
class StaffAccessReady extends InboxNotification
{
    /** @param list<string> $names */
    public function __construct(
        public readonly string $handoverUuid,
        public readonly array $names,
        public readonly string $sentBy,
        public readonly int $days,
    ) {}

    public function payload(): array
    {
        $count = count($this->names);
        $shown = array_slice($this->names, 0, 3);
        $others = $count - count($shown);
        $who = implode(', ', $shown).($others > 0 ? ' et '.$others.' autre'.($others > 1 ? 's' : '') : '');

        return [
            'kind' => 'staff_access.ready',
            'category' => 'staff_access',
            'title' => $count === 1 ? 'Un accès créé pour le personnel' : $count.' accès créés pour le personnel',
            'body' => $who.' — prévenez '.($count === 1 ? 'l’employé' : 'chaque employé').' : il se connecte avec son adresse et choisit son mot de passe, dans les '.$this->days.' jours. Envoyé par '.$this->sentBy.'.',
            'url' => '/administration/staff-access/'.$this->handoverUuid,
            'icon' => 'key-round',
            'tone' => 'primary',
            'meta' => ['handover_uuid' => $this->handoverUuid, 'count' => $count],
        ];
    }
}
