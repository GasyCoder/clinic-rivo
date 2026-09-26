<?php

namespace App\Services\Webmail;

/**
 * ADR-195 — ouvre la boîte d'une adresse. En production, la vraie boîte chez
 * l'hébergeur ; les tests remplacent cette fabrique par une boîte en mémoire.
 */
class MailServerFactory
{
    /**
     * @throws WebmailAuthenticationFailed
     * @throws WebmailUnavailable
     */
    public function connect(string $address, #[\SensitiveParameter] string $password): MailServer
    {
        return ImapMailServer::connect($address, $password);
    }

    /**
     * La boîte, ouverte au premier usage seulement (LazyMailServer) : pour les
     * requêtes de la messagerie, une fois le mot de passe vérifié à l'ouverture.
     */
    public function open(string $address, #[\SensitiveParameter] string $password): MailServer
    {
        return new LazyMailServer(fn () => $this->connect($address, $password));
    }
}
