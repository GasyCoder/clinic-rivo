<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * ADR-098, ADR-181 — quand deux libellés désignent le même produit.
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
    /**
     * Un mot d'un seul côté qui inverse le produit. « Compresse stérile » et
     * « compresse non stérile » ne sont pas le même article, et leurs
     * libellés ne diffèrent que par lui.
     */
    private const NEGATIONS = ['non', 'sans'];

    public static function normalize(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    /**
     * Deux libellés qui se ressemblent assez pour qu'on demande à un humain
     * s'ils désignent le même produit — jamais assez pour en décider.
     *
     * Ce n'est pas une égalité approximative : c'est une règle prudente, qui
     * préfère ne rien proposer à proposer un rapprochement faux. Un
     * rapprochement faux crée un prix d'achat sur le mauvais produit, et le
     * stock suit.
     *
     *   les nombres font le produit   500 mg et 1 g, 125 ml et 250 ml, 18G et
     *                                 25G ne sont pas le même article : deux
     *                                 jeux de nombres incompatibles écartent
     *   une négation compte           « stérile » et « non stérile » aussi
     *   les mots doivent s'emboîter   l'un des deux libellés dit tout ce que
     *                                 dit l'autre, et parfois davantage —
     *                                 « Alcool 1l 70° » et « ALCOOL ETHYLIQUE
     *                                 70% 1L », mais pas « Gants taille M » et
     *                                 « Gants taille L »
     */
    public static function looksLikeSameProduct(?string $first, ?string $second): bool
    {
        $left = self::normalize($first);
        $right = self::normalize($second);

        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        [$leftNumbers, $leftWords] = self::split($left);
        [$rightNumbers, $rightWords] = self::split($right);

        foreach (self::NEGATIONS as $negation) {
            if (in_array($negation, $leftWords, true) !== in_array($negation, $rightWords, true)) {
                return false;
            }
        }

        if (! self::oneHoldsTheOther($leftNumbers, $rightNumbers)) {
            return false;
        }

        // Un libellé réduit à des nombres ne dit pas quel produit c'est.
        if ($leftWords === [] || $rightWords === []) {
            return false;
        }

        // L'un dit tout ce que dit l'autre — mots **et** nombres, dans le même
        // sens. Vérifiés chacun de leur côté, « Alcool 125ml 70° » et
        // « ALCOOL IODE SALICYLE IMRA 125ML » passaient : le premier seul dit
        // 70°, le second seul dit iodé salicylé — deux produits différents,
        // rapprochés sur Ambondromamy le 2026-09-24.
        return self::oneHoldsTheOther([...$leftNumbers, ...$leftWords], [...$rightNumbers, ...$rightWords]);
    }

    /**
     * Les morceaux d'un libellé normalisé : « 500mg » et « 500 mg » donnent
     * les mêmes, sans quoi la même dose écrite de deux façons se lirait comme
     * deux produits.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function split(string $normalized): array
    {
        preg_match_all('/\d+|[a-z]+/', $normalized, $matches);

        $numbers = [];
        $words = [];

        foreach ($matches[0] as $token) {
            if (ctype_digit($token)) {
                // « 05 » et « 5 » sont le même nombre.
                $numbers[] = ltrim($token, '0') ?: '0';
            } else {
                $words[] = $token;
            }
        }

        return [array_values(array_unique($numbers)), array_values(array_unique($words))];
    }

    /**
     * @param  array<int, string>  $first
     * @param  array<int, string>  $second
     */
    private static function oneHoldsTheOther(array $first, array $second): bool
    {
        return array_diff($first, $second) === [] || array_diff($second, $first) === [];
    }
}
