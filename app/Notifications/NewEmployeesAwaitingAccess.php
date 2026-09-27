<?php

namespace App\Notifications;

/**
 * ADR-197 — portail : des employés ajoutés par le RH d'un site attendent leur
 * accès (compte RIVO et adresse pro). Le portail l'apprend en lisant l'API du
 * site ; il ne notifie qu'une fois chaque ajout.
 *
 * ADR-199 — elle retient quels employés elle annonce : dès qu'aucun d'eux
 * n'attend plus, le portail la marque « Traité » (`StaffAccessWatcher::resolve()`).
 */
class NewEmployeesAwaitingAccess extends InboxNotification
{
    /**
     * @param  list<string>  $names
     * @param  list<string>  $employeeUuids  les employés annoncés, pour savoir quand ils sont servis
     */
    public function __construct(
        public readonly string $siteCode,
        public readonly string $siteName,
        public readonly array $names,
        public readonly int $count,
        public readonly array $employeeUuids = [],
    ) {}

    public function payload(): array
    {
        $shown = array_slice($this->names, 0, 3);
        $others = $this->count - count($shown);
        $who = implode(', ', $shown).($others > 0 ? ' et '.$others.' autre'.($others > 1 ? 's' : '') : '');

        return [
            'kind' => 'staff_access.pending',
            'category' => 'staff_access',
            'title' => $this->count === 1
                ? 'Un nouvel employé à '.$this->siteName.' attend son accès'
                : $this->count.' nouveaux employés à '.$this->siteName.' attendent leur accès',
            'body' => $who.' : compte RIVO et adresse professionnelle à créer.',
            'url' => '/super-admin/staff-access?site='.rawurlencode($this->siteCode),
            'icon' => 'user-plus',
            'tone' => 'primary',
            'meta' => [
                'site_code' => $this->siteCode,
                'site_name' => $this->siteName,
                'count' => $this->count,
                'employee_uuids' => array_values($this->employeeUuids),
                'waiting' => $this->count,
            ],
        ];
    }
}
