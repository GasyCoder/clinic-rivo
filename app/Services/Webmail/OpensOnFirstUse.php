<?php

namespace App\Services\Webmail;

/**
 * Une boîte ouverte seulement quand on s'en sert : `connected()` dit si le serveur
 * de messagerie a déjà été joint — et donc accepté le mot de passe — dans cette
 * requête (ADR-195, ADR-200).
 */
interface OpensOnFirstUse
{
    public function connected(): bool;
}
