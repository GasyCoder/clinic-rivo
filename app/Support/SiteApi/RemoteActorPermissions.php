<?php

namespace App\Support\SiteApi;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Les permissions du Super Administrateur, transmises du portail à l'API d'un site.
 *
 * Dans un seul en-tête en clair, le catalogue complet (≈ 10 Ko, et il grandit) dépasse
 * la taille qu'Apache accepte pour un en-tête (LimitRequestFieldSize, 8 190 octets) :
 * l'hébergeur répondait « 400 Bad Request » en HTML, avant même Laravel, et le portail
 * affichait « L'API du site a refusé la requête ». La liste part donc compressée
 * (deflate, base64url) et découpée en morceaux de CHUNK_LENGTH caractères :
 *
 *   X-Rivo-Actor-Permissions-Gz-1, X-Rivo-Actor-Permissions-Gz-2, …
 *
 * L'ancien en-tête en clair (X-Rivo-Actor-Permissions) reste lu quand aucun morceau
 * n'est envoyé : un portail pas encore mis à jour continue de fonctionner tant que sa
 * liste tient dans un en-tête.
 */
final class RemoteActorPermissions
{
    public const PLAIN_HEADER = 'X-Rivo-Actor-Permissions';

    public const CHUNK_HEADER = 'X-Rivo-Actor-Permissions-Gz-';

    /** Bien en dessous des 8 190 octets d'Apache, nom de l'en-tête compris. */
    public const CHUNK_LENGTH = 4000;

    /** 16 × 4 000 caractères compressés : très au-delà de tout catalogue réaliste. */
    public const MAX_CHUNKS = 16;

    /** La liste décompressée, en clair : la même borne que l'ancien en-tête. */
    public const MAX_LENGTH = 32_768;

    /**
     * @param  iterable<string>  $names
     * @return array<string, string>
     */
    public static function headers(iterable $names): array
    {
        $list = collect($names)->implode(',');

        if ($list === '') {
            return [];
        }

        $encoded = rtrim(strtr(base64_encode((string) gzdeflate($list, 9)), '+/', '-_'), '=');
        $headers = [];

        foreach (str_split($encoded, self::CHUNK_LENGTH) as $index => $chunk) {
            $headers[self::CHUNK_HEADER.($index + 1)] = $chunk;
        }

        if (count($headers) > self::MAX_CHUNKS) {
            throw new InvalidArgumentException('La liste des permissions est trop longue pour être transmise au site.');
        }

        return $headers;
    }

    /**
     * La liste en clair reçue par le site, séparée par des virgules ; '' quand rien n'est transmis.
     *
     * @throws InvalidArgumentException quand les morceaux sont illisibles ou trop longs
     */
    public static function raw(Request $request): string
    {
        return self::decode(fn (string $name): ?string => $request->header($name));
    }

    /**
     * La même liste, lue sur une requête envoyée par le portail (tests du client HTTP).
     */
    public static function sent(ClientRequest $request): string
    {
        return self::decode(fn (string $name): ?string => $request->header($name)[0] ?? null);
    }

    /**
     * @param  callable(string): ?string  $header
     *
     * @throws InvalidArgumentException
     */
    private static function decode(callable $header): string
    {
        $encoded = '';

        for ($index = 1; $index <= self::MAX_CHUNKS + 1; $index++) {
            $chunk = $header(self::CHUNK_HEADER.$index);

            if ($chunk === null) {
                break;
            }

            if ($index > self::MAX_CHUNKS) {
                throw new InvalidArgumentException('L’en-tête des permissions de l’acteur distant dépasse la taille autorisée.');
            }

            $encoded .= trim($chunk);
        }

        if ($encoded === '') {
            return (string) $header(self::PLAIN_HEADER);
        }

        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);
        // La borne protège aussi d'une archive qui gonflerait démesurément.
        $list = $binary === false ? false : @gzinflate($binary, self::MAX_LENGTH + 1);

        if ($list === false) {
            throw new InvalidArgumentException('L’en-tête des permissions de l’acteur distant est illisible.');
        }

        return $list;
    }
}
