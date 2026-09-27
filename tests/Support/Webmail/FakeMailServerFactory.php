<?php

namespace Tests\Support\Webmail;

use App\Services\Webmail\MailServer;
use App\Services\Webmail\MailServerFactory;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailUnavailable;

/** ADR-195 — la fabrique des tests : une boîte en mémoire par adresse, un mot de passe chacune. */
final class FakeMailServerFactory extends MailServerFactory
{
    /** @var array<string, array{password: string, server: FakeMailServer}> */
    public array $boxes = [];

    public bool $offline = false;

    /** Les connexions réellement ouvertes : un rechargement partiel n'en ouvre aucune. */
    public int $connections = 0;

    public function box(string $address, string $password = 'secret-boite'): FakeMailServer
    {
        return ($this->boxes[$address] ??= ['password' => $password, 'server' => new FakeMailServer])['server'];
    }

    public function changePassword(string $address, string $password): void
    {
        $this->boxes[$address]['password'] = $password;
    }

    public function connect(string $address, #[\SensitiveParameter] string $password): MailServer
    {
        $this->connections++;

        if ($this->offline) {
            throw WebmailUnavailable::because('Le serveur de messagerie ne répond pas. Réessayez dans un instant.');
        }

        if (! isset($this->boxes[$address]) || $this->boxes[$address]['password'] !== $password) {
            throw WebmailAuthenticationFailed::refused();
        }

        return $this->boxes[$address]['server'];
    }
}
