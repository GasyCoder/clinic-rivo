<?php

namespace App\Services\Webmail\KeepAlive;

use App\Services\Webmail\MailServer;
use App\Services\Webmail\MailServerFactory;
use App\Services\Webmail\WebmailAuthenticationFailed;
use App\Services\Webmail\WebmailUnavailable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-195 — le processus qui garde ouverte la connexion d'une boîte
 * (MailboxConnectionPool). Il écoute sur sa prise Unix, sert les requêtes de la
 * messagerie une à une, et se ferme seul après quelques minutes sans usage, sur
 * demande (déconnexion de RIVO), ou quand le serveur refuse le mot de passe.
 *
 * Chaque requête HTTP ouvre sa propre prise : ce qu'une requête a lu d'avance ne sert
 * pas à la suivante (`beginRequest`). Avant de réutiliser la connexion, il vérifie
 * qu'elle tient toujours (`alive`), sans aller-retour ; sinon il se reconnecte.
 *
 * Un routeur (NAT, box, veille du poste) oublie une connexion muette au bout de
 * quelques minutes sans prévenir aucun des deux bouts : `alive` la croit ouverte, et
 * chaque lecture attendrait tout le délai avant d'échouer (constaté le 2026-09-30).
 * Entre deux clics, il l'entretient donc d'un NOOP chaque minute (`heartbeat`) et la
 * rouvre aussitôt si le NOOP ne revient pas ; une connexion restée muette plus
 * longtemps (poste en veille) est vérifiée avant d'être réutilisée ; et après un
 * échec, elle n'est jamais gardée : l'état du flux est inconnu.
 */
final class MailboxWorker
{
    /** Ce que la messagerie peut demander : les méthodes de lecture et d'écriture de la boîte. */
    private const METHODS = ['folders', 'messages', 'uids', 'message', 'attachment', 'raw', 'flag', 'move', 'delete', 'append', 'createFolder', 'quota'];

    /** Le délai laissé à un NOOP de vérification : un aller-retour tient en moins d'une seconde. */
    private const PROBE_TIMEOUT = 5.0;

    private ?MailServer $mail = null;

    /** La dernière réponse du serveur de messagerie (microtime). */
    private float $lastContact = 0.0;

    /** @var resource|null */
    private $server = null;

    public function __construct(
        private readonly MailServerFactory $factory,
        private readonly MailboxConnectionPool $pool,
        private readonly string $path,
        private readonly string $address,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $tokenHash,
        private readonly int $idleSeconds,
        private readonly bool $prime = false,
        private readonly int $heartbeatSeconds = 60,
    ) {}

    public function run(): int
    {
        if (! $this->listen()) {
            return 0;
        }

        register_shutdown_function(fn () => $this->close());

        // Arrêté par le système (redémarrage, `kill`) : la prise disparaît avec lui.
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
                pcntl_signal($signal, fn () => exit(0));
            }
        }

        try {
            // La connexion s'établit tout de suite : le premier clic la trouve prête. Lancé
            // d'avance (connexion à RIVO), il lit aussi la première page de la réception :
            // le premier clic sur « Messagerie » n'aura plus qu'un aller-retour.
            try {
                $mail = $this->mail();

                if ($this->prime) {
                    $mail->plan('INBOX');
                    $mail->folders();
                    $mail->messages('INBOX', 1, (int) config('rivo.webmail.per_page', 25));
                }
            } catch (WebmailAuthenticationFailed) {
                $this->pool->rememberRefusal($this->path);

                // La prochaine requête l'apprendra en se reconnectant elle-même.
                return 0;
            } catch (Throwable) {
                // Le serveur ne répond pas : la requête suivante réessaiera.
            }

            $lastRequest = microtime(true);

            while (true) {
                $idleLeft = $this->idleSeconds - (microtime(true) - $lastRequest);

                if ($idleLeft <= 0) {
                    return 0;
                }

                $readable = [$this->server];
                $none = null;
                $ready = @stream_select($readable, $none, $none, (int) max(1, ceil(min($idleLeft, max(1, $this->heartbeatSeconds)))));

                if ($ready === 0) {
                    if (! $this->heartbeat()) {
                        return 0;
                    }

                    continue;
                }

                if ($ready === false) {
                    continue;
                }

                $client = @stream_socket_accept($this->server, 0);

                if ($client === false) {
                    continue;
                }

                $keepGoing = $this->serve($client);
                @fclose($client);
                $lastRequest = microtime(true);

                if (! $keepGoing) {
                    return 0;
                }
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Les demandes d'une requête HTTP, sur sa prise, jusqu'à ce qu'elle la ferme.
     *
     * @param  resource  $client
     * @return bool false : le processus s'arrête
     */
    public function serve($client): bool
    {
        stream_set_timeout($client, 30);
        $began = false;

        while (($request = WorkerProtocol::read($client)) !== null) {
            if (! hash_equals($this->tokenHash, hash('sha256', (string) ($request['token'] ?? '')))) {
                WorkerProtocol::write($client, ['ok' => false, 'error' => 'forbidden']);

                return true;
            }

            $method = (string) ($request['method'] ?? '');

            if ($method === 'shutdown') {
                WorkerProtocol::write($client, ['ok' => true, 'value' => null]);

                return false;
            }

            if (! in_array($method, self::METHODS, true)) {
                WorkerProtocol::write($client, ['ok' => false, 'error' => 'unavailable', 'message' => 'Demande inconnue.']);

                continue;
            }

            try {
                $mail = $this->mail(checkAlive: ! $began);

                if (! $began && $mail instanceof KeepsConnectionOpen) {
                    $mail->beginRequest();
                }
                $began = true;

                if (is_array($plan = $request['plan'] ?? null) && count($plan) >= 4) {
                    $mail->plan((string) $plan[0], (array) $plan[1], $plan[2] === null ? null : (int) $plan[2], (bool) $plan[3], max(1, (int) ($plan[4] ?? 1)));
                }

                $value = $mail->{$method}(...array_values((array) ($request['args'] ?? [])));
                $this->lastContact = microtime(true);
                WorkerProtocol::write($client, ['ok' => true, 'value' => $value]);
            } catch (WebmailAuthenticationFailed) {
                // Mot de passe changé chez l'hébergeur : ce processus ne sert plus à rien.
                $this->pool->rememberRefusal($this->path);
                WorkerProtocol::write($client, ['ok' => false, 'error' => 'auth']);

                return false;
            } catch (WebmailUnavailable $exception) {
                WorkerProtocol::write($client, ['ok' => false, 'error' => 'unavailable', 'message' => $exception->getMessage()]);
                $this->drop();
            } catch (Throwable $exception) {
                Log::warning('Messagerie : processus de connexion — '.$method, [
                    'address' => $this->address,
                    'error' => $exception::class.': '.mb_substr($exception->getMessage(), 0, 300),
                ]);
                WorkerProtocol::write($client, ['ok' => false, 'error' => 'unavailable', 'message' => 'Le serveur de messagerie ne répond pas. Réessayez dans un instant.']);
                $this->drop();
            }
        }

        return true;
    }

    /** La connexion, ouverte ou rouverte si elle ne répond plus. */
    private function mail(bool $checkAlive = false): MailServer
    {
        if ($this->mail !== null && $checkAlive && ! $this->answering()) {
            $this->drop();
        }

        if ($this->mail === null) {
            $this->mail = $this->factory->connect($this->address, $this->password);
            $this->lastContact = microtime(true);
        }

        return $this->mail;
    }

    /**
     * La connexion tient, sans aller-retour ; restée muette plus d'un battement (poste
     * en veille, battement manqué), un NOOP le vérifie : quelques centaines de
     * millisecondes plutôt que tout le délai d'une lecture qui ne reviendra pas.
     */
    private function answering(): bool
    {
        if (! $this->mail instanceof KeepsConnectionOpen) {
            return true;
        }

        if (! $this->mail->alive()) {
            return false;
        }

        if (microtime(true) - $this->lastContact < $this->heartbeatSeconds) {
            return true;
        }

        if (! $this->mail->probe(self::PROBE_TIMEOUT)) {
            return false;
        }

        $this->lastContact = microtime(true);

        return true;
    }

    /**
     * Entre deux clics : un NOOP garde la connexion connue du routeur, et s'il ne
     * revient pas, elle est rouverte tout de suite — jamais au moment du clic.
     *
     * @return bool false : le serveur refuse désormais le mot de passe
     */
    private function heartbeat(): bool
    {
        if ($this->mail !== null && ! $this->mail instanceof KeepsConnectionOpen) {
            return true;
        }

        if ($this->mail instanceof KeepsConnectionOpen && $this->mail->alive() && $this->mail->probe(self::PROBE_TIMEOUT)) {
            $this->lastContact = microtime(true);

            return true;
        }

        $this->drop();

        try {
            $this->mail();
        } catch (WebmailAuthenticationFailed) {
            $this->pool->rememberRefusal($this->path);

            return false;
        } catch (Throwable) {
            // Le serveur ne répond pas : le battement suivant, ou la requête suivante, réessaiera.
        }

        return true;
    }

    /** Après un échec, l'état du flux est inconnu : la connexion n'est jamais réutilisée. */
    private function drop(): void
    {
        try {
            $this->mail?->disconnect();
        } catch (Throwable) {
        }

        $this->mail = null;
    }

    /** Écoute sur sa prise — sauf si un autre processus sert déjà cette boîte. */
    private function listen(): bool
    {
        if (file_exists($this->path)) {
            $existing = @stream_socket_client('unix://'.$this->path, $code, $message, 1.0);

            if ($existing !== false) {
                fclose($existing);

                return false;
            }

            @unlink($this->path);
        }

        $previous = umask(0077);

        try {
            $server = @stream_socket_server('unix://'.$this->path, $code, $message);
        } finally {
            umask($previous);
        }

        if ($server === false) {
            return false;
        }

        @chmod($this->path, 0600);
        $this->server = $server;

        return true;
    }

    private function close(): void
    {
        if (is_resource($this->server)) {
            @fclose($this->server);
            @unlink($this->path);
        }

        $this->server = null;

        try {
            $this->mail?->disconnect();
        } catch (Throwable) {
        }

        $this->mail = null;
    }
}
