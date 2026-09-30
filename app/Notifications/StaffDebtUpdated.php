<?php

namespace App\Notifications;

/**
 * ADR-228 — sur le site : ce qui arrive à une dette du personnel, annoncé à l'employé
 * qui l'a demandée (accordée, refusée, ajustée, versée, soldée, remise, annulée) et au
 * RH qui la verse. Ni motif médical ni salaire : un titre, un montant, un lien.
 */
class StaffDebtUpdated extends InboxNotification
{
    public const KINDS = [
        'approved' => ['icon' => 'circle-check', 'tone' => 'success'],
        'refused' => ['icon' => 'circle-x', 'tone' => 'danger'],
        'adjusted' => ['icon' => 'pencil', 'tone' => 'primary'],
        'cancelled' => ['icon' => 'ban', 'tone' => 'neutral'],
        'to_disburse' => ['icon' => 'hand-coins', 'tone' => 'warning'],
        'disbursed' => ['icon' => 'banknote', 'tone' => 'primary'],
        'settled' => ['icon' => 'circle-check', 'tone' => 'success'],
        'written_off' => ['icon' => 'gift', 'tone' => 'success'],
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $debtUuid,
        public readonly string $number,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
    ) {}

    public function payload(): array
    {
        $look = self::KINDS[$this->kind] ?? ['icon' => 'hand-coins', 'tone' => 'primary'];

        return [
            'kind' => 'staff_debt.'.$this->kind,
            'category' => 'staff_debts',
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $look['icon'],
            'tone' => $look['tone'],
            'meta' => ['debt_uuid' => $this->debtUuid, 'number' => $this->number],
        ];
    }
}
