<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * ADR-098 — quand deux libellés désignent le même produit.
 *
 * Deux fournisseurs écrivent rarement un médicament de la même façon, et un
 * même catalogue liste parfois le même produit sous deux références. La
 * comparaison ignore donc les accents, la casse et la ponctuation.
 *
 * Cette règle vit ici, et nulle part ailleurs : la création d'un médicament
 * depuis une ligne de catalogue et le formulaire de commande doivent juger
 * identiques exactement les mêmes libellés, sans quoi l'écran proposerait
 * deux fois un produit que le serveur refuse ensuite de commander deux fois.
 */
final class ProductLabel
{
    public static function normalize(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
