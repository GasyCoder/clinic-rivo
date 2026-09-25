<?php

namespace App\Support\Authorization;

use App\Models\RemoteSuperAdmin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * ADR-187 — l'auteur d'un geste fait depuis le portail, pour une fiche qui
 * affiche son auteur.
 *
 * Le Super Admin distant n'a pas de compte sur le site : la colonne `*_by`
 * reste vide, et son UUID et son nom vont dans `external_*_by_uuid/name`. Pour
 * un compte local, ces deux champs sont vidés : ils disent toujours qui a fait
 * le dernier geste, jamais un reste d'un geste précédent.
 */
final class RemoteActorAttribution
{
    /** @return array<string, string|null> */
    public static function fields(string $verb, ?Authenticatable $actor = null): array
    {
        $actor ??= Auth::user();
        $remote = $actor instanceof RemoteSuperAdmin;

        return [
            "external_{$verb}_by_uuid" => $remote ? $actor->externalUuid() : null,
            "external_{$verb}_by_name" => $remote ? $actor->externalName() : null,
        ];
    }

    /** Le nom à afficher : le compte local, sinon le Super Admin du portail. */
    public static function name(?string $localName, ?string $externalName): ?string
    {
        if (filled($localName)) {
            return $localName;
        }

        return filled($externalName) ? $externalName.' (portail)' : null;
    }
}
