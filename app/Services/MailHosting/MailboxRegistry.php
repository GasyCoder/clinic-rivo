<?php

namespace App\Services\MailHosting;

use App\Models\User;

/**
 * ADR-190 — là où l'état des adresses est enregistré : la base du site.
 *
 * Le portail y accède par l'API du site (RemoteMailboxRegistry), le site par
 * sa propre base (LocalMailboxRegistry). Les deux répondent la même forme :
 * `ok`, `data` (l'adresse présentée), `message`, `errors`, `http_status`.
 */
interface MailboxRegistry
{
    /** @return array<string, mixed> */
    public function find(string $site, string $mailboxUuid, User $actor): array;

    /**
     * @param  array{employee_uuid: string, local_part: string, note?: string}  $payload
     * @return array<string, mixed>
     */
    public function request(string $site, array $payload, User $actor): array;

    /**
     * `activate`, `reject`, `suspend` ou `reactivate`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function command(string $site, string $mailboxUuid, string $command, array $payload, User $actor): array;
}
