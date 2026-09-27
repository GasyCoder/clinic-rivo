<?php

namespace App\Services\Webmail\KeepAlive;

/**
 * Une boîte dont la connexion peut servir plusieurs requêtes de suite
 * (MailboxWorker) : ce qui a été lu d'avance pour la précédente est oublié, et
 * la connexion est vérifiée — sans aller-retour — avant d'être réutilisée.
 */
interface KeepsConnectionOpen
{
    public function beginRequest(): void;

    public function alive(): bool;
}
