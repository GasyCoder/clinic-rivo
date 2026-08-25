<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class StartLocalSiteApis extends Command
{
    /** @var string */
    protected $signature = 'rivo:local-apis
        {--reset : Recréer explicitement les trois bases SQLite locales}
        {--prepare-only : Préparer les bases sans démarrer les serveurs HTTP}';

    /** @var string */
    protected $description = 'Préparer et démarrer les API locales isolées des sites cliniques';

    /** @var array<int, Process> */
    private array $processes = [];

    private bool $stopRequested = false;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Cette commande est strictement réservée au développement local.');

            return self::FAILURE;
        }

        if (! config('rivo.local_site_apis.enabled')) {
            $this->error('Les API locales sont désactivées. Définissez RIVO_LOCAL_SITE_APIS=true.');

            return self::FAILURE;
        }

        $sites = collect(config('rivo.local_site_apis.sites', []))
            ->filter(fn (mixed $site): bool => is_array($site))
            ->values();

        if ($sites->isEmpty()) {
            $this->error('Aucun site local n’est configuré.');

            return self::FAILURE;
        }

        $this->components->info('Préparation des API cliniques locales isolées');
        $this->line('Le portail Super Admin conserve sa base actuelle ; chaque clinique utilise son propre fichier SQLite.');

        try {
            $runningSites = [];

            foreach ($sites as $site) {
                $status = $this->runningApiStatus($site);

                if ($status === 'local-api') {
                    if ($this->option('reset')) {
                        throw new RuntimeException(
                            "Arrêtez d’abord l’API {$site['name']} avant d’utiliser --reset.",
                        );
                    }

                    $runningSites[] = $site['code'];
                    $this->components->twoColumnDetail($site['name'], "<fg=yellow>déjà active sur :{$site['port']}</>");

                    continue;
                }

                if ($status === 'occupied') {
                    throw new RuntimeException(
                        "Le port {$site['port']} de {$site['name']} est déjà utilisé par un autre service.",
                    );
                }

                $this->prepareSite($site, (bool) $this->option('reset'));
            }

            if ($this->option('prepare-only')) {
                $this->newLine();
                $this->components->success('Les trois bases locales sont prêtes.');

                return self::SUCCESS;
            }

            foreach ($sites as $site) {
                if (in_array($site['code'], $runningSites, true)) {
                    continue;
                }

                $this->startSite($site);
            }

            foreach ($sites as $site) {
                if (! $this->waitUntilReady($site)) {
                    throw new RuntimeException(
                        "L’API {$site['name']} n’a pas répondu correctement sur le port {$site['port']}.",
                    );
                }

                $this->components->twoColumnDetail(
                    $site['name'],
                    "<fg=green>en ligne</> — http://{$site['host']}:{$site['port']}/api/v1",
                );
            }

            $this->newLine();
            $this->components->success('Stock, adresses, prestations, tarifs et mutuelles sont accessibles depuis le portail Super Admin local.');

            if ($this->processes === []) {
                $this->line('<fg=gray>Les trois API étaient déjà démarrées ; aucun nouveau processus à maintenir.</>');

                return self::SUCCESS;
            }

            $this->line('<fg=gray>Laissez cette commande ouverte. Utilisez Ctrl+C pour arrêter les API démarrées ici.</>');

            $this->registerSignalHandlers();

            return $this->monitorProcesses();
        } catch (Throwable $exception) {
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->stopProcesses();
        }
    }

    /** @param array<string, mixed> $site */
    private function prepareSite(array $site, bool $reset): void
    {
        $databasePath = $this->databasePath($site);
        $wasCreated = false;

        File::ensureDirectoryExists(dirname($databasePath));

        if ($reset && File::exists($databasePath)) {
            File::delete($databasePath);
        }

        if (! File::exists($databasePath)) {
            File::put($databasePath, '');
            $wasCreated = true;
        }

        $this->components->task("{$site['name']} — migrations", function () use ($site): void {
            $this->runArtisan($site, ['migrate', '--force', '--no-interaction']);
        });

        try {
            if ($wasCreated) {
                $this->components->task("{$site['name']} — référentiels et comptes de test", function () use ($site): void {
                    $this->runArtisan($site, ['db:seed', '--class=Database\\Seeders\\DatabaseSeeder', '--force', '--no-interaction']);
                    $this->runArtisan($site, ['db:seed', '--class=Database\\Seeders\\DevelopmentUserSeeder', '--force', '--no-interaction']);
                });
            }

            $this->components->task("{$site['name']} — permissions à jour", function () use ($site): void {
                $this->runArtisan($site, ['db:seed', '--class=Database\\Seeders\\PermissionSeeder', '--force', '--no-interaction']);
                $this->runArtisan($site, ['db:seed', '--class=Database\\Seeders\\RolePermissionSeeder', '--force', '--no-interaction']);
            });

            if (! $this->siteHasClinicalCatalog($site)) {
                $this->components->task("{$site['name']} — prestations et tarifs de test", function () use ($site): void {
                    $this->runArtisan($site, ['db:seed', '--class=Database\\Seeders\\ClinicalServiceCatalogSeeder', '--force', '--no-interaction']);
                });
            }

            $this->components->task("{$site['name']} — mutuelles et partenaires de test", function () use ($site): void {
                $this->runArtisan($site, ['db:seed', '--class=Database\\Seeders\\DevelopmentMutualOrganizationSeeder', '--force', '--no-interaction']);
            });

            if ($wasCreated) {
                $this->components->task("{$site['name']} — stock Pharmacie de test", function () use ($site): void {
                    $this->runArtisan($site, ['db:seed', '--class=Database\\Seeders\\DevelopmentMedicineStockSeeder', '--force', '--no-interaction']);
                });
            }
        } catch (Throwable $exception) {
            if ($wasCreated) {
                File::delete($databasePath);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $site */
    private function runArtisan(array $site, array $arguments): void
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$arguments],
            base_path(),
            $this->siteEnvironment($site),
        );
        $process->setTimeout(240);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: trim($process->getOutput());

            throw new RuntimeException($message !== '' ? $message : 'Une commande de préparation a échoué.');
        }
    }

    /** @param array<string, mixed> $site */
    private function startSite(array $site): void
    {
        $process = new Process(
            [
                PHP_BINARY,
                'artisan',
                'serve',
                "--host={$site['host']}",
                "--port={$site['port']}",
                '--tries=1',
                '--no-reload',
                '--no-interaction',
                '--quiet',
            ],
            base_path(),
            $this->siteEnvironment($site),
        );
        $process->setTimeout(null);
        $process->start();

        $this->processes[] = $process;
    }

    /** @param array<string, mixed> $site */
    private function siteEnvironment(array $site): array
    {
        $suffix = mb_strtolower((string) $site['code']);

        return [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'APP_KEY' => (string) config('app.key'),
            'APP_URL' => "http://{$site['host']}:{$site['port']}",
            'RIVO_SITE_CODE' => (string) $site['code'],
            'RIVO_SITE_NAME' => (string) $site['name'],
            'RIVO_SITE_TYPE' => 'clinic',
            'RIVO_SITE_API_TOKEN' => (string) $site['token'],
            'RIVO_CATALOG_SEED_ACTOR' => "administration.{$suffix}@rivo.test",
            'RIVO_LOCAL_SITE_APIS' => 'false',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->databasePath($site),
            'DB_FOREIGN_KEYS' => 'true',
            'SESSION_DRIVER' => 'file',
            'SESSION_COOKIE' => "rivo_local_{$suffix}_session",
            'CACHE_STORE' => 'file',
            'CACHE_PREFIX' => "rivo_local_{$suffix}",
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'log',
            'LOG_CHANNEL' => 'single',
        ];
    }

    /** @param array<string, mixed> $site */
    private function databasePath(array $site): string
    {
        $directory = rtrim((string) config('rivo.local_site_apis.database_directory'), DIRECTORY_SEPARATOR);

        return $directory.DIRECTORY_SEPARATOR.Str::slug((string) $site['name']).'.sqlite';
    }

    /** @param array<string, mixed> $site */
    private function siteHasClinicalCatalog(array $site): bool
    {
        $pdo = new PDO('sqlite:'.$this->databasePath($site));
        $statement = $pdo->query("SELECT COUNT(*) FROM catalog_items WHERE type = 'SERVICE'");

        return (int) $statement->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $site */
    private function runningApiStatus(array $site): string
    {
        $socket = @fsockopen((string) $site['host'], (int) $site['port'], $errorCode, $errorMessage, 0.25);

        if (! is_resource($socket)) {
            return 'free';
        }

        fclose($socket);

        return $this->apiResponds($site) ? 'local-api' : 'occupied';
    }

    /** @param array<string, mixed> $site */
    private function waitUntilReady(array $site): bool
    {
        $deadline = microtime(true) + 15;

        do {
            if ($this->apiResponds($site)) {
                return true;
            }

            usleep(150_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** @param array<string, mixed> $site */
    private function apiResponds(array $site): bool
    {
        try {
            return Http::acceptJson()
                ->withToken((string) $site['token'])
                ->withHeaders([
                    'X-Request-UUID' => (string) Str::uuid(),
                    'X-Rivo-Actor-UUID' => (string) Str::uuid(),
                    'X-Rivo-Actor-Name' => 'Lanceur local RIVO',
                ])
                ->connectTimeout(1)
                ->timeout(2)
                ->get("http://{$site['host']}:{$site['port']}/api/v1/super-admin/pharmacy/stock")
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_async_signals') || ! defined('SIGINT')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (): void {
            $this->stopRequested = true;
            $this->newLine();
            $this->line('Arrêt des API locales…');
        });

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, function (): void {
                $this->stopRequested = true;
            });
        }
    }

    private function monitorProcesses(): int
    {
        while (! $this->stopRequested) {
            foreach ($this->processes as $process) {
                if ($process->isRunning()) {
                    continue;
                }

                $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());
                $this->components->error($error !== '' ? $error : 'Une API locale s’est arrêtée de façon inattendue.');

                return self::FAILURE;
            }

            usleep(250_000);
        }

        return self::SUCCESS;
    }

    private function stopProcesses(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(2, defined('SIGTERM') ? SIGTERM : null);
            }
        }

        $this->processes = [];
    }
}
