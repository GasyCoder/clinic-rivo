<?php

namespace App\Services\Webmail;

use Webklex\PHPIMAP\Client;

/**
 * ADR-194 — le client IMAP de la bibliothèque, sans ses allers-retours inutiles.
 *
 * La bibliothèque vérifie la connexion avant CHAQUE commande en envoyant un NOOP
 * au serveur : à 260 ms l'aller-retour (Madagascar ↔ o2switch), une page de dossier
 * en payait des dizaines. Ici la connexion vit le temps d'une requête HTTP : elle
 * est ouverte au début, et une coupure se lit à la commande suivante, comme une
 * panne (ImapMailServer::guard).
 *
 * La déconnexion n'attend pas non plus la réponse au LOGOUT : rien n'en dépend.
 */
final class LeanImapClient extends Client
{
    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->getStream() !== false && $this->connection->getStream() !== null;
    }

    public function checkConnection(): bool
    {
        return false;
    }

    public function disconnect(): Client
    {
        $protocol = $this->connection;

        if ($protocol !== null) {
            $stream = $protocol->getStream();

            if (is_resource($stream)) {
                @fwrite($stream, "Z LOGOUT\r\n");
                @fclose($stream);
            }

            $protocol->reset();
        }

        $this->connection = null;
        $this->setActiveFolder(null);

        return $this;
    }
}
