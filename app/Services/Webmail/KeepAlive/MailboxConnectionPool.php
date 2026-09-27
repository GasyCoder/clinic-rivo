<?php

namespace App\Services\Webmail\KeepAlive;

use App\Services\Webmail\MailServer;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-195 (amendement du 2026-09-26) — la connexion au serveur de messagerie gardée
 * ouverte entre les clics.
 *
 * Se connecter coûte cher : depuis Madagascar, 0,8 à 4 s de liaison, chiffrement et
 * identification avant de lire un seul message. PHP ne garde rien d'une requête à
 * l'autre ; chaque clic payait donc tout. Un petit processus par boîte ouverte
 * (MailboxWorker, `php artisan rivo:webmail-worker`) garde la connexion : seul le
 * premier usage la paie, les clics suivants ne paient plus que la lecture.
 *
 *  - lancé à la demande, par la messagerie, jamais à la main ; il se ferme seul après
 *    quelques minutes sans usage, et à la déconnexion de RIVO ;
 *  - le mot de passe lui est remis par un tube (jamais dans sa ligne de commande), il
 *    ne vit que dans sa mémoire — jamais en base, jamais dans un journal ;
 *  - joint par une prise Unix locale, dans un dossier réservé au compte du serveur
 *    web, et seulement avec un jeton que seul un détenteur du mot de passe calcule ;
 *  - une boîte et un mot de passe = un processus, une connexion au serveur.
 *
 * Sans processus possible (hébergement qui les interdit, démarrage trop lent), RIVO se
 * connecte à chaque clic, comme avant, et n'essaie plus pendant dix minutes.
 */
final class MailboxConnectionPool
{
    private const BROKEN = 'webmail.keep_alive.broken';

    private const REFUSED = 'webmail.keep_alive.refused.';

    public function enabled(): bool
    {
        return (bool) config('rivo.webmail.keep_alive.enabled')
            && PHP_OS_FAMILY !== 'Windows'
            && function_exists('proc_open')
            && ! Cache::has(self::BROKEN);
    }

    /**
     * La boîte, jointe par le processus qui garde sa connexion — ou, s'il ne démarre
     * pas, directement, par `$direct`.
     *
     * @param  Closure(): MailServer  $direct
     */
    public function open(string $address, #[\SensitiveParameter] string $password, Closure $direct): PooledMailServer
    {
        return new PooledMailServer($this, $address, $password, $direct);
    }

    /**
     * Démarre le processus de cette boîte sans l'attendre : sa connexion s'établit
     * pendant que l'employé fait autre chose, et le premier clic sur la messagerie la
     * trouve prête. Rien si le serveur a déjà refusé ce mot de passe.
     */
    public function warm(string $address, #[\SensitiveParameter] string $password): void
    {
        if (! $this->enabled() || Cache::has(self::REFUSED.$this->name($address, $password))) {
            return;
        }

        $path = $this->socketPath($address, $password);

        if (($stream = $this->dial($path)) !== null) {
            fclose($stream);

            return;
        }

        // Lancé d'avance : il lit aussi la première page de la réception, que le premier clic
        // demandera. Lancé par une requête, il la sert directement.
        $this->spawn($path, $address, $password, prime: true);
    }

    /**
     * Une prise vers le processus de cette boîte, démarré s'il le faut — `null` s'il
     * ne peut pas démarrer.
     *
     * @return resource|null
     */
    public function connect(string $address, #[\SensitiveParameter] string $password)
    {
        $path = $this->socketPath($address, $password);

        if (($stream = $this->dial($path)) !== null) {
            return $stream;
        }

        if (! $this->spawn($path, $address, $password)) {
            $this->markBroken('le processus ne peut pas être lancé');

            return null;
        }

        $deadline = microtime(true) + (float) config('rivo.webmail.keep_alive.start_timeout', 5);

        do {
            usleep(20_000);

            if (($stream = $this->dial($path)) !== null) {
                return $stream;
            }
        } while (microtime(true) < $deadline);

        $this->markBroken('le processus n’a pas démarré à temps');

        return null;
    }

    /** Ferme la connexion de cette boîte : déconnexion de RIVO, boîte refermée. */
    public function stop(string $address, #[\SensitiveParameter] string $password): void
    {
        if (! config('rivo.webmail.keep_alive.enabled')) {
            return;
        }

        $stream = $this->dial($this->socketPath($address, $password));

        if ($stream === null) {
            return;
        }

        stream_set_timeout($stream, 2);
        WorkerProtocol::write($stream, ['token' => $this->token($address, $password), 'method' => 'shutdown']);
        WorkerProtocol::read($stream);
        fclose($stream);
    }

    public function token(string $address, #[\SensitiveParameter] string $password): string
    {
        return $this->derive('token', $address, $password);
    }

    /** Le serveur a refusé ce mot de passe : `warm()` ne le réessaie plus aujourd'hui. */
    public function rememberRefusal(string $socketPath): void
    {
        Cache::put(self::REFUSED.basename($socketPath, '.sock'), true, now()->addDay());
    }

    public function socketPath(string $address, #[\SensitiveParameter] string $password): string
    {
        return $this->directory().'/'.$this->name($address, $password).'.sock';
    }

    /**
     * Le dossier des prises, réservé au compte du serveur web. Une prise Unix tient en
     * 108 caractères : un chemin d'installation trop long passe par le dossier temporaire.
     */
    public function directory(): string
    {
        $directory = (string) (config('rivo.webmail.keep_alive.path') ?: storage_path('framework/webmail'));

        if (strlen($directory) > 70) {
            $directory = rtrim(sys_get_temp_dir(), '/').'/rivo-webmail-'.(function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        }

        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        @chmod($directory, 0700);

        return $directory;
    }

    /** @return resource|null */
    private function dial(string $path)
    {
        if (! file_exists($path)) {
            return null;
        }

        $stream = @stream_socket_client('unix://'.$path, $code, $message, 1.0);

        if ($stream === false) {
            return null;
        }

        // Le processus peut attendre le serveur de messagerie : le même délai, plus une marge.
        stream_set_timeout($stream, (int) config('rivo.webmail.timeout', 20) + 10);

        return $stream;
    }

    private function spawn(string $path, string $address, #[\SensitiveParameter] string $password, bool $prime = false): bool
    {
        $worker = [$this->php(), base_path('artisan'), 'rivo:webmail-worker', $path];
        $setsid = $this->setsid();

        // Détaché de la requête qui le lance : `setsid -f` rend la main aussitôt ; sans lui,
        // un shell le met en arrière-plan et garde le tube sur un autre descripteur.
        $command = $setsid !== null
            ? [$setsid, '-f', ...$worker]
            : ['/bin/sh', '-c', 'exec 3<&0; "$@" <&3 >/dev/null 2>&1 &', 'sh', ...$worker];

        try {
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path());

            if (! is_resource($process)) {
                return false;
            }

            // Le mot de passe passe par le tube : jamais dans la ligne de commande, que `ps` montre.
            fwrite($pipes[0], json_encode([
                'address' => $address,
                'password' => $password,
                'token_hash' => hash('sha256', $this->token($address, $password)),
                'idle_seconds' => max(60, (int) config('rivo.webmail.keep_alive.idle_minutes', 10) * 60),
                'prime' => $prime,
            ], JSON_THROW_ON_ERROR)."\n");
            fclose($pipes[0]);
            proc_close($process);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function php(): string
    {
        $configured = trim((string) config('rivo.webmail.keep_alive.php'));

        if ($configured !== '') {
            return $configured;
        }

        if (in_array(PHP_SAPI, ['cli', 'cli-server', 'phpdbg'], true)) {
            return PHP_BINARY;
        }

        // Sous PHP-FPM, PHP_BINARY désigne php-fpm : le PHP en ligne de commande est à côté.
        foreach ([PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, PHP_BINDIR.'/php', '/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    private function setsid(): ?string
    {
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function name(string $address, #[\SensitiveParameter] string $password): string
    {
        return substr($this->derive('socket', $address, $password), 0, 32);
    }

    /**
     * Tiré de la boîte ET de son mot de passe, avec la clé de l'application : un autre
     * mot de passe donne un autre processus, et seul qui le connaît trouve la prise et
     * calcule son jeton. Le site et le portail d'une même installation ne se croisent pas.
     */
    private function derive(string $purpose, string $address, #[\SensitiveParameter] string $password): string
    {
        return hash_hmac('sha256', implode("\0", [
            $purpose,
            (string) config('rivo.site.type'),
            (string) config('rivo.site.code'),
            mb_strtolower(trim($address)),
            $password,
        ]), (string) config('app.key'));
    }

    private function markBroken(string $reason): void
    {
        Cache::put(self::BROKEN, true, now()->addMinutes(10));
        Log::warning('Messagerie : connexion gardée ouverte indisponible, connexion à chaque clic pendant dix minutes', ['reason' => $reason]);
    }
}
