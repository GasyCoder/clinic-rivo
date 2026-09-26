<?php

namespace App\Services\Webmail;

use RuntimeException;

/**
 * ADR-195 — le serveur de messagerie ne répond pas, ou refuse une commande. Le
 * message est une phrase montrée telle quelle : jamais l'erreur technique brute,
 * jamais un mot de passe.
 */
class WebmailUnavailable extends RuntimeException
{
    public static function because(string $what, ?\Throwable $previous = null): self
    {
        return new self($what, 0, $previous);
    }
}
