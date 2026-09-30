<?php

namespace Tests\Support\Webmail;

use App\Services\Webmail\KeepAlive\KeepsConnectionOpen;
use App\Services\Webmail\MailServer;
use App\Services\Webmail\WebmailUnavailable;
use Symfony\Component\Mime\Email;

/**
 * ADR-195 — une connexion gardée ouverte, pour les tests du processus (MailboxWorker) :
 * la boîte en mémoire derrière, et une connexion qu'un routeur peut « oublier » sans
 * prévenir (`forgotten`) — elle se croit ouverte (`alive`), mais plus rien ne revient :
 * ni le NOOP (`probe`), ni aucune lecture, qui échoue au bout du délai.
 */
final class KeptOpenMailServer implements KeepsConnectionOpen, MailServer
{
    public int $probes = 0;

    public int $calls = 0;

    public bool $disconnected = false;

    public function __construct(
        private readonly FakeMailServer $box,
        public bool $forgotten = false,
    ) {}

    public function beginRequest(): void {}

    public function alive(): bool
    {
        return ! $this->disconnected;
    }

    public function probe(float $timeout): bool
    {
        $this->probes++;

        return ! $this->disconnected && ! $this->forgotten;
    }

    public function plan(string $folder, array $criteria = [], ?int $uid = null, bool $markSeen = false, int $page = 1): void
    {
        $this->box->plan($folder, $criteria, $uid, $markSeen, $page);
    }

    public function folders(bool $withCounts = true): array
    {
        return $this->through(fn () => $this->box->folders($withCounts), 'lire les dossiers');
    }

    public function messages(string $folder, int $page, int $perPage, array $criteria = []): array
    {
        return $this->through(fn () => $this->box->messages($folder, $page, $perPage, $criteria), 'lire le dossier');
    }

    public function uids(string $folder, array $criteria = []): array
    {
        return $this->through(fn () => $this->box->uids($folder, $criteria), 'lire le dossier');
    }

    public function message(string $folder, int $uid): ?array
    {
        return $this->through(fn () => $this->box->message($folder, $uid), 'lire le message');
    }

    public function attachment(string $folder, int $uid, string $part): ?array
    {
        return $this->through(fn () => $this->box->attachment($folder, $uid, $part), 'lire la pièce jointe');
    }

    public function raw(string $folder, int $uid): ?string
    {
        return $this->through(fn () => $this->box->raw($folder, $uid), 'lire le message');
    }

    public function flag(string $folder, array $uids, string $flag, bool $on): void
    {
        $this->through(fn () => $this->box->flag($folder, $uids, $flag, $on), 'marquer les messages');
    }

    public function move(string $folder, array $uids, string $target): void
    {
        $this->through(fn () => $this->box->move($folder, $uids, $target), 'déplacer les messages');
    }

    public function delete(string $folder, array $uids): void
    {
        $this->through(fn () => $this->box->delete($folder, $uids), 'supprimer les messages');
    }

    public function append(string $folder, string $raw, array $flags = []): void
    {
        $this->through(fn () => $this->box->append($folder, $raw, $flags), 'enregistrer le message');
    }

    public function createFolder(string $path): void
    {
        $this->through(fn () => $this->box->createFolder($path), 'créer le dossier');
    }

    public function quota(): ?array
    {
        return $this->through(fn () => $this->box->quota(), 'lire l’espace utilisé');
    }

    public function send(Email $email): void
    {
        $this->box->send($email);
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function through(callable $read, string $what): mixed
    {
        $this->calls++;

        if ($this->disconnected || $this->forgotten) {
            // Ce que la vraie connexion dit au bout du délai (ImapMailServer::guard).
            throw WebmailUnavailable::because("Impossible de {$what} : le serveur de messagerie ne répond pas.");
        }

        return $read();
    }
}
