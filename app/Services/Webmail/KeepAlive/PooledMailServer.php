<?php

namespace App\Services\Webmail\KeepAlive;

use App\Services\Webmail\MailServer;
use App\Services\Webmail\OpensOnFirstUse;
use App\Services\Webmail\SmtpSender;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailUnavailable;
use Closure;
use Symfony\Component\Mime\Email;

/**
 * ADR-195 — la boîte, lue par la connexion que son processus garde ouverte
 * (MailboxConnectionPool). Chaque méthode part par la prise locale et revient avec
 * la réponse du serveur de messagerie ; la lecture annoncée (`plan`) part avec la
 * demande suivante, sans aller-retour de plus.
 *
 * Rien ne change pour la messagerie : mêmes réponses, mêmes erreurs. Si le processus
 * ne démarre pas, ou tombe pendant une lecture, la requête se connecte elle-même,
 * comme avant. Une écriture interrompue n'est jamais rejouée : on ne sait pas si elle
 * a eu lieu, l'écran le dit.
 *
 * L'envoi ne passe pas par le processus : il a sa propre connexion (SmtpSender).
 */
final class PooledMailServer implements MailServer, OpensOnFirstUse
{
    private const READS = ['folders', 'messages', 'uids', 'message', 'attachment', 'raw', 'quota'];

    /** @var resource|null */
    private $stream = null;

    private bool $dialed = false;

    private bool $answered = false;

    private ?MailServer $direct = null;

    /** @var array{0: string, 1: array<string, mixed>, 2: ?int, 3: bool, 4: int}|null */
    private ?array $plan = null;

    /** @param Closure(): MailServer $connectDirectly */
    public function __construct(
        private readonly MailboxConnectionPool $pool,
        private readonly string $address,
        #[\SensitiveParameter] private readonly string $password,
        private readonly Closure $connectDirectly,
    ) {}

    public function connected(): bool
    {
        return $this->answered || $this->direct !== null;
    }

    /** Joint par son processus plutôt que par une connexion à lui. */
    public function keptOpen(): bool
    {
        return $this->answered && $this->direct === null;
    }

    public function plan(string $folder, array $criteria = [], ?int $uid = null, bool $markSeen = false, int $page = 1): void
    {
        if ($this->direct !== null) {
            $this->direct->plan($folder, $criteria, $uid, $markSeen, $page);

            return;
        }

        $this->plan = [$folder, $criteria, $uid, $markSeen, $page];
    }

    public function folders(bool $withCounts = true): array
    {
        return $this->call('folders', [$withCounts]);
    }

    public function messages(string $folder, int $page, int $perPage, array $criteria = []): array
    {
        return $this->call('messages', [$folder, $page, $perPage, $criteria]);
    }

    public function uids(string $folder, array $criteria = []): array
    {
        return $this->call('uids', [$folder, $criteria]);
    }

    public function message(string $folder, int $uid): ?array
    {
        return $this->call('message', [$folder, $uid]);
    }

    public function attachment(string $folder, int $uid, string $part): ?array
    {
        return $this->call('attachment', [$folder, $uid, $part]);
    }

    public function raw(string $folder, int $uid): ?string
    {
        return $this->call('raw', [$folder, $uid]);
    }

    public function flag(string $folder, array $uids, string $flag, bool $on): void
    {
        $this->call('flag', [$folder, $uids, $flag, $on]);
    }

    public function move(string $folder, array $uids, string $target): void
    {
        $this->call('move', [$folder, $uids, $target]);
    }

    public function delete(string $folder, array $uids): void
    {
        $this->call('delete', [$folder, $uids]);
    }

    public function append(string $folder, string $raw, array $flags = []): void
    {
        $this->call('append', [$folder, $raw, $flags]);
    }

    public function createFolder(string $path): void
    {
        $this->call('createFolder', [$path]);
    }

    public function quota(): ?array
    {
        return $this->call('quota', []);
    }

    public function send(Email $email): void
    {
        SmtpSender::send($this->address, $this->password, $email);
    }

    /** La prise vers le processus se ferme ; sa connexion au serveur, elle, reste ouverte. */
    public function disconnect(): void
    {
        $this->closeStream();
        $this->direct?->disconnect();
        $this->direct = null;
    }

    private function call(string $method, array $args): mixed
    {
        if ($this->direct !== null) {
            return $this->direct->{$method}(...$args);
        }

        if (! $this->dialed) {
            $this->dialed = true;
            $this->stream = $this->pool->connect($this->address, $this->password);
        }

        $plan = $this->plan;
        $this->plan = null;

        if ($this->stream === null) {
            return $this->directly($method, $args, $plan);
        }

        $sent = WorkerProtocol::write($this->stream, [
            'token' => $this->pool->token($this->address, $this->password),
            'plan' => $plan,
            'method' => $method,
            'args' => $args,
        ]);
        $reply = $sent ? WorkerProtocol::read($this->stream) : null;

        if ($reply === null) {
            $this->closeStream();

            // Une lecture se refait sans risque ; une écriture peut avoir eu lieu.
            if (in_array($method, self::READS, true)) {
                return $this->directly($method, $args, $plan);
            }

            throw WebmailUnavailable::because('La connexion au serveur de messagerie s’est interrompue : vérifiez le résultat, puis réessayez si besoin.');
        }

        if (($reply['ok'] ?? false) === true) {
            $this->answered = true;

            return $reply['value'] ?? null;
        }

        if (($reply['error'] ?? null) === 'auth') {
            throw WebmailAuthenticationFailed::refused();
        }

        if (($reply['error'] ?? null) === 'forbidden' && in_array($method, self::READS, true)) {
            $this->closeStream();

            return $this->directly($method, $args, $plan);
        }

        throw WebmailUnavailable::because((string) ($reply['message'] ?? 'Le serveur de messagerie ne répond pas. Réessayez dans un instant.'));
    }

    /** @param array{0: string, 1: array<string, mixed>, 2: ?int, 3: bool, 4: int}|null $plan */
    private function directly(string $method, array $args, ?array $plan): mixed
    {
        $this->direct = ($this->connectDirectly)();

        if ($plan !== null) {
            $this->direct->plan(...$plan);
        }

        return $this->direct->{$method}(...$args);
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }
}
