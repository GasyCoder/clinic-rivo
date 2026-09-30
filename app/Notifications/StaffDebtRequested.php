<?php

namespace App\Notifications;

/**
 * ADR-228 — portail : un membre du personnel d'un site demande une dette, le DG la
 * décide. Le portail l'apprend en relisant l'API du site (ADR-004) ; chaque demande
 * n'est signalée qu'une fois. Décidée, la notification se marque « Traité ».
 */
class StaffDebtRequested extends InboxNotification
{
    public function __construct(
        public readonly string $siteCode,
        public readonly string $siteName,
        public readonly string $debtUuid,
        public readonly string $number,
        public readonly string $employeeName,
        public readonly string $amount,
    ) {}

    public function payload(): array
    {
        return [
            'kind' => 'staff_debt.requested',
            'category' => 'staff_debts',
            'title' => "{$this->employeeName} demande une dette · {$this->siteName}",
            'body' => 'Demande '.$this->number.' de '.number_format((float) $this->amount, 0, ',', ' ').' Ar : à accorder, ajuster ou refuser.',
            'url' => '/super-admin/sites/'.rawurlencode($this->siteCode).'/rh/dettes/'.rawurlencode($this->debtUuid),
            'icon' => 'hand-coins',
            'tone' => 'warning',
            'meta' => [
                'site_code' => $this->siteCode,
                'site_name' => $this->siteName,
                'debt_uuid' => $this->debtUuid,
                'number' => $this->number,
            ],
        ];
    }
}
