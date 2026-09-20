<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Routing\Route;

/**
 * ADR-154 — ce qu'une route exige, dit en clair quand elle refuse.
 *
 * « Cette action n'est pas autorisée. » ne permettait pas de savoir quel droit
 * accorder, ni où l'accorder. Le cas qui l'a montré : `hospitalization.view`
 * coché au socle du rôle, refusé nominativement sur le compte — le refus était
 * exact (DENY > socle, ADR-033) et se lisait pourtant comme un défaut de
 * l'application.
 *
 * Nommer la permission n'expose rien : l'écran le fait déjà partout ailleurs
 * (« Non visible avec vos droits (maternity.view) »), et le destinataire est un
 * professionnel authentifié de la clinique.
 */
class RequiredAbilities
{
    /**
     * Les capacités qu'une route exige par son `can:` — lues sur la route
     * réellement appariée, jamais devinées d'après l'URL.
     *
     * @return list<string>
     */
    public static function forRoute(?Route $route): array
    {
        if ($route === null) {
            return [];
        }

        $abilities = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            // `can:permission,model` — seule la capacité nous intéresse.
            $ability = explode(',', mb_substr($middleware, 4))[0];

            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $ability)) {
                $abilities[] = $ability;
            }
        }

        return array_values(array_unique($abilities));
    }

    /**
     * Le message : ce qui manque, et — c'est tout l'intérêt — où le régler.
     *
     * Un droit que le rôle accorde mais que le compte refuse ne se règle pas
     * au même endroit qu'un droit que personne n'a accordé. Confondre les deux
     * fait cocher une case qui restera sans effet.
     *
     * @param  list<string>  $missing
     */
    public static function explain(User $user, array $missing): string
    {
        $labels = Permission::query()
            ->whereIn('name', $missing)
            ->pluck('label', 'name');

        $named = collect($missing)
            ->map(fn (string $name) => trim((string) ($labels[$name] ?? '')) !== ''
                ? "« {$labels[$name]} » ({$name})"
                : $name)
            ->join(', ', ' et ');

        $deniedPersonally = $user->permissions()
            ->wherePivot('effect', 'deny')
            ->whereIn('permissions.name', $missing)
            ->pluck('permissions.name');

        if ($deniedPersonally->isNotEmpty()) {
            return 'Ce droit vous est refusé personnellement : '.$named.'. '
                .'Le refus individuel l’emporte sur le socle du rôle, même coché. '
                .'Il se lève dans « Rôles & permissions » › « Exceptions par compte », '
                .'en remettant le droit sur « Selon le rôle ».';
        }

        return 'Il vous manque le droit '.$named.'. '
            .'Il s’accorde dans « Rôles & permissions » : au socle du rôle, ou en exception sur votre compte.';
    }
}
