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

    /** La connexion tient, d'après ce que le flux en dit — sans rien envoyer. */
    public function alive(): bool;

    /**
     * Le serveur répond vraiment (un NOOP), dans le délai donné. Une connexion qu'un
     * routeur a oubliée sans prévenir ne se voit qu'ainsi : `alive()` la croit ouverte.
     */
    public function probe(float $timeout): bool;
}
