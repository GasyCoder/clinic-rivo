<?php

namespace App\Console\Commands;

use App\Services\Webmail\KeepAlive\MailboxConnectionPool;
use App\Services\Webmail\KeepAlive\MailboxWorker;
use App\Services\Webmail\MailServerFactory;
use Illuminate\Console\Command;

/**
 * ADR-195 — le processus qui garde ouverte la connexion d'une boîte. Lancé par la
 * messagerie (MailboxConnectionPool), jamais à la main : la boîte, son mot de passe
 * et le jeton attendu arrivent par l'entrée standard, jamais sur la ligne de commande.
 */
class RunWebmailWorker extends Command
{
    protected $signature = 'rivo:webmail-worker {socket : La prise Unix de ce processus}';

    protected $description = 'Garder ouverte la connexion d’une boîte email (lancé par la messagerie)';

    protected $hidden = true;

    public function handle(MailServerFactory $factory, MailboxConnectionPool $pool): int
    {
        $line = defined('STDIN') ? fgets(STDIN, 8192) : false;
        $init = is_string($line) ? json_decode(trim($line), true) : null;

        if (! is_array($init) || ! is_string($init['address'] ?? null) || ! is_string($init['password'] ?? null) || ! is_string($init['token_hash'] ?? null)) {
            return self::FAILURE;
        }

        $path = (string) $this->argument('socket');

        // Seulement dans le dossier des prises de la messagerie.
        if (dirname($path) !== $pool->directory() || ! str_ends_with($path, '.sock')) {
            return self::FAILURE;
        }

        return (new MailboxWorker(
            $factory,
            $pool,
            $path,
            $init['address'],
            $init['password'],
            $init['token_hash'],
            max(60, (int) ($init['idle_seconds'] ?? 600)),
            (bool) ($init['prime'] ?? false),
            max(15, (int) config('rivo.webmail.keep_alive.heartbeat_seconds', 60)),
        ))->run();
    }
}
