<?php

namespace App\Services\MailHosting;

use App\Models\User;
use App\Services\SuperAdmin\PortalSiteApiClient;

/** ADR-190 — le portail : l'état des adresses se lit et s'écrit par l'API du site (ADR-004). */
final class RemoteMailboxRegistry implements MailboxRegistry
{
    public function __construct(private readonly PortalSiteApiClient $sites) {}

    public function find(string $site, string $mailboxUuid, User $actor): array
    {
        return $this->sites->professionalMailbox($site, $mailboxUuid, $actor);
    }

    public function request(string $site, array $payload, User $actor): array
    {
        return $this->sites->requestProfessionalMailbox($site, $payload, $actor);
    }

    public function command(string $site, string $mailboxUuid, string $command, array $payload, User $actor): array
    {
        return $this->sites->professionalMailbox($site, $mailboxUuid, $actor, $command, $payload);
    }
}
