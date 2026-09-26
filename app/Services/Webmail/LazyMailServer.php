<?php

namespace App\Services\Webmail;

use Closure;
use Symfony\Component\Mime\Email;

/**
 * ADR-195 — la boîte, ouverte seulement quand on s'en sert.
 *
 * Se connecter coûte ~1,1 s depuis Madagascar (chiffrement et identification) : une
 * requête qui n'a rien à demander au serveur de messagerie — un rechargement partiel
 * après une action, un libellé renommé — ne le paie plus. Une adresse refusée ou un
 * serveur injoignable se lit au premier usage, comme avant (bootstrap/app.php).
 */
final class LazyMailServer implements MailServer
{
    private ?MailServer $server = null;

    /** @var array{0: string, 1: array<string, mixed>, 2: ?int, 3: bool}|null Une lecture annoncée avant la connexion. */
    private ?array $plan = null;

    /** @param Closure(): MailServer $connect */
    public function __construct(private readonly Closure $connect) {}

    public function connected(): bool
    {
        return $this->server !== null;
    }

    public function folders(bool $withCounts = true): array
    {
        return $this->server()->folders($withCounts);
    }

    public function plan(string $folder, array $criteria = [], ?int $uid = null, bool $markSeen = false): void
    {
        // Annoncer ce qu'on lira ensuite ne justifie pas d'ouvrir la connexion.
        if ($this->server === null) {
            $this->plan = [$folder, $criteria, $uid, $markSeen];

            return;
        }

        $this->server->plan($folder, $criteria, $uid, $markSeen);
    }

    public function messages(string $folder, int $page, int $perPage, array $criteria = []): array
    {
        return $this->server()->messages($folder, $page, $perPage, $criteria);
    }

    public function uids(string $folder, array $criteria = []): array
    {
        return $this->server()->uids($folder, $criteria);
    }

    public function message(string $folder, int $uid): ?array
    {
        return $this->server()->message($folder, $uid);
    }

    public function attachment(string $folder, int $uid, string $part): ?array
    {
        return $this->server()->attachment($folder, $uid, $part);
    }

    public function raw(string $folder, int $uid): ?string
    {
        return $this->server()->raw($folder, $uid);
    }

    public function flag(string $folder, array $uids, string $flag, bool $on): void
    {
        $this->server()->flag($folder, $uids, $flag, $on);
    }

    public function move(string $folder, array $uids, string $target): void
    {
        $this->server()->move($folder, $uids, $target);
    }

    public function delete(string $folder, array $uids): void
    {
        $this->server()->delete($folder, $uids);
    }

    public function append(string $folder, string $raw, array $flags = []): void
    {
        $this->server()->append($folder, $raw, $flags);
    }

    public function createFolder(string $path): void
    {
        $this->server()->createFolder($path);
    }

    public function quota(): ?array
    {
        return $this->server()->quota();
    }

    public function send(Email $email): void
    {
        $this->server()->send($email);
    }

    public function disconnect(): void
    {
        $this->server?->disconnect();
        $this->server = null;
    }

    private function server(): MailServer
    {
        if ($this->server === null) {
            $this->server = ($this->connect)();

            if ($this->plan !== null) {
                $this->server->plan(...$this->plan);
                $this->plan = null;
            }
        }

        return $this->server;
    }
}
