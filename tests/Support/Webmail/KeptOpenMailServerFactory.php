<?php

namespace Tests\Support\Webmail;

use App\Services\Webmail\MailServer;
use App\Services\Webmail\MailServerFactory;

/** ADR-195 — chaque connexion du processus, gardée pour être « oubliée » par le test. */
final class KeptOpenMailServerFactory extends MailServerFactory
{
    /** @var list<KeptOpenMailServer> */
    public array $opened = [];

    /** @var list<bool> les connexions à ouvrir déjà oubliées, par rang (0 : la première) */
    public array $forgotten = [];

    public function __construct(public readonly FakeMailServer $box = new FakeMailServer) {}

    public function connect(string $address, #[\SensitiveParameter] string $password): MailServer
    {
        $connection = new KeptOpenMailServer($this->box, $this->forgotten[count($this->opened)] ?? false);
        $this->opened[] = $connection;

        return $connection;
    }
}
