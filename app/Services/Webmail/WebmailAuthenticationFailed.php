<?php

namespace App\Services\Webmail;

/** ADR-194 — le serveur refuse l'adresse et le mot de passe. */
class WebmailAuthenticationFailed extends WebmailUnavailable
{
    public const MESSAGE = 'Le serveur de messagerie refuse ce mot de passe.';

    public static function refused(?\Throwable $previous = null): self
    {
        return new self(self::MESSAGE, 0, $previous);
    }
}
