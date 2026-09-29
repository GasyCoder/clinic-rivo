<?php

namespace Tests\Feature\Webmail;

use App\Services\Webmail\KeepAlive\MailboxConnectionPool;
use App\Services\Webmail\KeepAlive\MailboxWorker;
use App\Services\Webmail\KeepAlive\PooledMailServer;
use App\Services\Webmail\KeepAlive\WorkerProtocol;
use App\Services\Webmail\LazyMailServer;
use App\Services\Webmail\MailServerFactory;
use App\Services\Webmail\WebmailUnavailable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Webmail\FakeMailServerFactory;
use Tests\TestCase;

/**
 * ADR-195 (amendement du 2026-09-26) — la connexion au serveur de messagerie gardée
 * ouverte entre les clics : un processus par boîte, joint par une prise locale et
 * un jeton ; les mêmes réponses qu'une connexion directe, et un repli sans rien
 * perdre quand il ne démarre pas ou tombe.
 */
class KeepAliveTest extends TestCase
{
    private const ADDRESS = 'soa.rakoto@cliniquesaintgeorges.mg';

    private const PASSWORD = 'secret-boite';

    private FakeMailServerFactory $factory;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/rivo-ka-'.bin2hex(random_bytes(3));
        config([
            'rivo.site.type' => 'clinic',
            'rivo.site.code' => 'A',
            'rivo.webmail.keep_alive.enabled' => true,
            'rivo.webmail.keep_alive.path' => $this->directory,
            'rivo.webmail.keep_alive.start_timeout' => 0.4,
        ]);
        $this->factory = new FakeMailServerFactory;
        $this->app->instance(MailServerFactory::class, $this->factory);
        $this->factory->box(self::ADDRESS, self::PASSWORD)->seed('INBOX', ['subject' => 'Résultats']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function test_a_frame_carries_data_and_never_revives_an_object(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $this->assertTrue(WorkerProtocol::write($a, ['value' => ['sujet' => 'Résultats', 'binaire' => "\x00\xff%PDF"], 'objet' => new \ArrayObject([1])]));
        $read = WorkerProtocol::read($b);

        $this->assertSame(['sujet' => 'Résultats', 'binaire' => "\x00\xff%PDF"], $read['value']);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $read['objet'], 'aucun objet recréé à la lecture');

        // Une trame démesurée est refusée, sans la lire.
        fwrite($a, pack('N', WorkerProtocol::MAX_FRAME_BYTES + 1));
        $this->assertNull(WorkerProtocol::read($b));
    }

    public function test_the_worker_serves_several_requests_on_one_connection(): void
    {
        $worker = $this->worker();

        $first = $this->exchange($worker, [
            ['plan' => ['INBOX', [], null, false, 2], 'method' => 'folders', 'args' => [true]],
            ['method' => 'messages', 'args' => ['INBOX', 1, 25, []]],
        ]);
        $this->assertTrue($first['keep']);
        $this->assertTrue($first['replies'][0]['ok']);
        $this->assertSame('Résultats', $first['replies'][1]['value']['items'][0]['subject']);
        $this->assertSame([['INBOX', [], null, false, 2]], array_map(fn (array $plan) => array_values($plan), $this->factory->box(self::ADDRESS)->plans), 'la lecture annoncée part avec la demande suivante');

        // Une autre requête HTTP, plus tard : la même connexion au serveur de messagerie.
        $second = $this->exchange($worker, [['method' => 'messages', 'args' => ['INBOX', 1, 25, []]]]);
        $this->assertTrue($second['replies'][0]['ok']);
        $this->assertSame(1, $this->factory->connections, 'une seule connexion pour les deux requêtes');
    }

    public function test_the_worker_answers_only_with_the_right_token_and_stops_on_request(): void
    {
        $worker = $this->worker();

        $forged = $this->exchange($worker, [['method' => 'messages', 'args' => ['INBOX', 1, 25, []]]], token: 'faux');
        $this->assertSame('forbidden', $forged['replies'][0]['error']);
        $this->assertSame(0, $this->factory->connections, 'rien n’est lu sans le jeton');

        $unknown = $this->exchange($worker, [['method' => 'send', 'args' => []]]);
        $this->assertSame('unavailable', $unknown['replies'][0]['error'], 'l’envoi ne passe jamais par le processus');

        $stop = $this->exchange($worker, [['method' => 'shutdown']]);
        $this->assertTrue($stop['replies'][0]['ok']);
        $this->assertFalse($stop['keep']);
    }

    public function test_a_refused_password_stops_the_worker_and_is_not_retried(): void
    {
        $worker = $this->worker(password: 'change-chez-l-hebergeur');

        $refused = $this->exchange($worker, [['method' => 'folders', 'args' => [true]]], token: $this->pool()->token(self::ADDRESS, 'change-chez-l-hebergeur'));

        $this->assertSame('auth', $refused['replies'][0]['error']);
        $this->assertFalse($refused['keep']);
        $this->assertTrue(Cache::has('webmail.keep_alive.refused.'.basename($this->pool()->socketPath(self::ADDRESS, 'change-chez-l-hebergeur'), '.sock')));
    }

    public function test_the_whole_loop_keeps_one_connection_across_requests(): void
    {
        $this->requireFork();
        $count = $this->directory.'/connections.txt';
        $path = $this->pool()->socketPath(self::ADDRESS, self::PASSWORD);

        $child = $this->fork(function () use ($path, $count): void {
            $this->worker($path)->run();
            file_put_contents($count, (string) $this->factory->connections);
        });

        $this->waitFor($path);

        // Deux requêtes HTTP : chacune ouvre la boîte, lit, puis la « ferme ».
        foreach ([1, 2] as $request) {
            $server = app(MailServerFactory::class)->open(self::ADDRESS, self::PASSWORD);
            $this->assertInstanceOf(PooledMailServer::class, $server);
            $server->plan('INBOX');
            $server->folders();
            $this->assertSame('Résultats', $server->messages('INBOX', 1, 25)['items'][0]['subject']);
            $this->assertTrue($server->keptOpen());
            $server->disconnect();
        }

        // Déconnexion de RIVO : le processus s'arrête.
        $this->pool()->stop(self::ADDRESS, self::PASSWORD);
        pcntl_waitpid($child, $status);

        $this->assertSame('1', @file_get_contents($count), 'une seule connexion au serveur pour les deux requêtes');
        $this->assertSame(0, $this->factory->connections, 'aucune connexion directe');
        $this->assertFileDoesNotExist($path, 'la prise disparaît avec le processus');
    }

    public function test_without_a_worker_the_request_connects_itself_and_stops_trying(): void
    {
        config(['rivo.webmail.keep_alive.php' => '/bin/false']);

        $server = app(MailServerFactory::class)->open(self::ADDRESS, self::PASSWORD);
        $this->assertSame('Résultats', $server->messages('INBOX', 1, 25)['items'][0]['subject']);
        $this->assertSame(1, $this->factory->connections, 'repli : connexion directe, comme avant');
        $this->assertFalse($server->keptOpen());

        // Dix minutes sans réessayer : plus d'attente au démarrage.
        $this->assertInstanceOf(LazyMailServer::class, app(MailServerFactory::class)->open(self::ADDRESS, self::PASSWORD));
    }

    public function test_a_worker_that_drops_the_line_is_replaced_for_reads_never_for_writes(): void
    {
        $this->requireFork();
        $path = $this->pool()->socketPath(self::ADDRESS, self::PASSWORD);
        @mkdir(dirname($path), 0700, true);

        // Un faux processus qui accepte puis raccroche sans répondre.
        $child = $this->fork(function () use ($path): void {
            $server = stream_socket_server('unix://'.$path);
            for ($i = 0; $i < 2; $i++) {
                $client = stream_socket_accept($server, 10);
                WorkerProtocol::read($client);
                fclose($client);
            }
            fclose($server);
            @unlink($path);
        });
        $this->waitFor($path);

        $read = app(MailServerFactory::class)->open(self::ADDRESS, self::PASSWORD);
        $this->assertSame('Résultats', $read->messages('INBOX', 1, 25)['items'][0]['subject'], 'une lecture se refait directement');
        $this->assertSame(1, $this->factory->connections);

        $write = app(MailServerFactory::class)->open(self::ADDRESS, self::PASSWORD);
        try {
            $write->flag('INBOX', [1], '\\Seen', true);
            $this->fail('une écriture interrompue n’est jamais rejouée');
        } catch (WebmailUnavailable $exception) {
            $this->assertStringContainsString('vérifiez le résultat', $exception->getMessage());
        }
        $this->assertSame([], $this->factory->box(self::ADDRESS)->stored('INBOX', 1)['flags']);

        pcntl_waitpid($child, $status);
    }

    public function test_the_socket_directory_is_private(): void
    {
        $directory = $this->pool()->directory();

        $this->assertSame('0700', substr(sprintf('%o', fileperms($directory)), -4));
        $this->assertLessThan(108, strlen($this->pool()->socketPath(self::ADDRESS, self::PASSWORD)), 'une prise Unix tient en 108 caractères');
        $this->assertNotSame(
            $this->pool()->socketPath(self::ADDRESS, self::PASSWORD),
            $this->pool()->socketPath(self::ADDRESS, 'autre-mot-de-passe'),
            'un autre mot de passe, un autre processus',
        );
        $this->assertStringNotContainsString(self::PASSWORD, $this->pool()->socketPath(self::ADDRESS, self::PASSWORD));
    }

    private function pool(): MailboxConnectionPool
    {
        return app(MailboxConnectionPool::class);
    }

    private function worker(?string $path = null, string $password = self::PASSWORD): MailboxWorker
    {
        return new MailboxWorker(
            $this->factory,
            $this->pool(),
            $path ?? $this->pool()->socketPath(self::ADDRESS, $password),
            self::ADDRESS,
            $password,
            hash('sha256', $this->pool()->token(self::ADDRESS, $password)),
            60,
        );
    }

    /**
     * Une requête HTTP : ses demandes sur une prise, puis la prise fermée.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return array{keep: bool, replies: list<array<string, mixed>>}
     */
    private function exchange(MailboxWorker $worker, array $requests, ?string $token = null): array
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $token ??= $this->pool()->token(self::ADDRESS, self::PASSWORD);

        foreach ($requests as $request) {
            WorkerProtocol::write($client, ['token' => $token, ...$request]);
        }
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $keep = $worker->serve($server);
        fclose($server);

        $replies = [];
        while (($reply = WorkerProtocol::read($client)) !== null) {
            $replies[] = $reply;
        }
        fclose($client);

        return ['keep' => $keep, 'replies' => $replies];
    }

    private function fork(\Closure $child): int
    {
        $pid = pcntl_fork();

        if ($pid === 0) {
            try {
                $child();
            } finally {
                // Jamais de retour dans PHPUnit depuis le processus enfant.
                posix_kill(getmypid(), SIGKILL);
            }
        }

        return $pid;
    }

    private function waitFor(string $path): void
    {
        for ($i = 0; $i < 200 && ! file_exists($path); $i++) {
            usleep(10_000);
        }
        $this->assertFileExists($path);
    }

    private function requireFork(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix requis pour lancer un processus de test.');
        }
    }
}
