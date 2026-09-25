<?php

namespace App\Http\Middleware;

use App\Models\RemoteSuperAdmin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-189 — un acte physique reste au site.
 *
 * Délivrer un médicament, servir un consommable, entrer une marchandise en
 * stock, compter un inventaire, ajuster un lot, réceptionner une livraison :
 * c'est la personne qui a les produits en main qui le fait (ADR-098). Le Super
 * Admin voit ces écrans depuis le portail et gère l'administratif, mais ces
 * gestes-là lui sont refusés ici, quel que soit l'écran qui les propose.
 */
class KeepPhysicalActsAtSite
{
    public const MESSAGE = 'Ce geste se fait à la Pharmacie du site, par la personne qui a les produits en main : le portail le consulte, il ne le fait pas.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof RemoteSuperAdmin) {
            return new JsonResponse(['message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
