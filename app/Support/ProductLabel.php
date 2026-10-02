<?php

namespace App\Support;

use App\Services\Pharmacy\ProductSynonyms;
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
        [$leftNumbers, $leftWords] = self::parts($first);
        [$rightNumbers, $rightWords] = self::parts($second);

        if (($leftNumbers === [] && $leftWords === []) || ($rightNumbers === [] && $rightWords === [])) {
            return false;
        }

        if (self::key($first) === self::key($second)) {
            return true;
        }

        foreach (self::NEGATIONS as $negation) {
            if (in_array($negation, $leftWords, true) !== in_array($negation, $rightWords, true)) {
                return false;
            }
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
        //
        // Les nombres se comptent : « 10x10 » n'est pas « 10x20 », bien que le
        // second contienne le premier nombre (ADR-241).
        return (self::contains($rightNumbers, $leftNumbers) && array_diff($leftWords, $rightWords) === [])
            || (self::contains($leftNumbers, $rightNumbers) && array_diff($rightWords, $leftWords) === []);
    }

    /**
     * ADR-241 — la forme canonique d'un libellé : ses mots et ses nombres une
     * fois les abréviations dépliées (« cp » = « comprimé »), les unités
     * ramenées à la plus petite (« 1 g » = « 1000 mg »), les pluriels et les
     * mots vides retirés, dans l'ordre alphabétique. Deux libellés qui ont la
     * même clé désignent le même produit : seul l'ordre des mots, une
     * abréviation ou une unité les séparait.
     */
    public static function key(?string $value): string
    {
        [$numbers, $words] = self::parts($value);

        $tokens = [...$numbers, ...$words];
        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    /**
     * Les mots canoniques d'un libellé, sans les nombres — ce qu'un index par
     * mot partage entre deux libellés.
     *
     * @return array<int, string>
     */
    public static function words(?string $value): array
    {
        return self::parts($value)[1];
    }

    /** Les abréviations livrées avec RIVO (ADR-241) : terme => forme canonique. */
    public const BUILT_IN_SYNONYMS = [
        // Formes
        'cp' => 'comprime', 'cpr' => 'comprime', 'cps' => 'comprime',
        'comprimes' => 'comprime', 'tab' => 'comprime', 'tabs' => 'comprime', 'tablet' => 'comprime', 'tablets' => 'comprime',
        'gelules' => 'gelule', 'gelu' => 'gelule', 'cap' => 'gelule', 'caps' => 'gelule', 'capsule' => 'gelule', 'capsules' => 'gelule',
        'inj' => 'injectable', 'injection' => 'injectable', 'injections' => 'injectable', 'inject' => 'injectable', 'injectables' => 'injectable',
        'amp' => 'ampoule', 'amps' => 'ampoule', 'ampoules' => 'ampoule',
        'sol' => 'solution', 'solutions' => 'solution',
        'sir' => 'sirop', 'syr' => 'sirop', 'syrup' => 'sirop', 'sirops' => 'sirop',
        'susp' => 'suspension', 'suspensions' => 'suspension',
        'fl' => 'flacon', 'flc' => 'flacon', 'fla' => 'flacon', 'flacons' => 'flacon',
        'pdre' => 'poudre', 'pdr' => 'poudre', 'poudres' => 'poudre',
        'cr' => 'creme', 'crm' => 'creme', 'cremes' => 'creme',
        'pom' => 'pommade', 'pde' => 'pommade', 'pommades' => 'pommade',
        'ovl' => 'ovule', 'ovules' => 'ovule',
        'supp' => 'suppositoire', 'suppo' => 'suppositoire', 'suppositoires' => 'suppositoire',
        'gtte' => 'goutte', 'gtt' => 'goutte', 'gttes' => 'goutte', 'gouttes' => 'goutte',
        'sach' => 'sachet', 'sachets' => 'sachet',
        'coll' => 'collyre', 'collyres' => 'collyre',
        'eff' => 'effervescent', 'efferv' => 'effervescent', 'effervescents' => 'effervescent',
        'perf' => 'perfusion', 'perfusions' => 'perfusion',
        'ser' => 'seringue', 'seringues' => 'seringue',
        // Conditionnement
        'bt' => 'boite', 'bte' => 'boite', 'btes' => 'boite', 'bts' => 'boite', 'boites' => 'boite',
        'pqt' => 'paquet', 'paquets' => 'paquet',
        'pce' => 'piece', 'pcs' => 'piece', 'pieces' => 'piece',
        'rlx' => 'rouleau', 'rlo' => 'rouleau', 'rouleaux' => 'rouleau',
        // Unités
        'gr' => 'g', 'grs' => 'g', 'gramme' => 'g', 'grammes' => 'g',
        'milligramme' => 'mg', 'milligrammes' => 'mg',
        'ug' => 'mcg', 'microgramme' => 'mcg', 'microgrammes' => 'mcg',
        'iu' => 'ui',
        'lt' => 'l', 'ltr' => 'l', 'litre' => 'l', 'litres' => 'l',
        'millilitre' => 'ml', 'millilitres' => 'ml',
    ];

    /** Des mots qui relient sans rien dire du produit. */
    private const STOP_WORDS = ['de', 'du', 'des', 'd', 'la', 'le', 'les', 'en', 'et', 'pour', 'avec', 'par', 'au', 'aux', 'x', 'un', 'une'];

    /**
     * Une unité ramenée à la plus petite de sa famille, quand un nombre la
     * précède : « 1 g » et « 1000 mg » sont la même dose.
     */
    private const UNIT_SCALES = [
        'kg' => ['mg', 1_000_000], 'g' => ['mg', 1000],
        'l' => ['ml', 1000], 'dl' => ['ml', 100], 'cl' => ['ml', 10],
    ];

    /** @var array<string, array{0: array<int, string>, 1: array<int, string>}> */
    private static array $cache = [];

    /** Oublie les formes déjà calculées : le dictionnaire du site a changé. */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Les nombres (comptés, dans l'ordre) et les mots canoniques (une fois
     * chacun) d'un libellé.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function parts(?string $value): array
    {
        $value = (string) $value;

        if (isset(self::$cache[$value])) {
            return self::$cache[$value];
        }

        if (count(self::$cache) > 20_000) {
            self::$cache = [];
        }

        $text = Str::of($value)->ascii()->lower()->toString();
        // « 0,5 » et « 0.5 » sont un nombre, pas deux.
        preg_match_all('/\d+(?:[.,]\d+)?|[a-z]+/', $text, $matches);

        $dictionary = self::dictionary();
        $numbers = [];
        $words = [];
        $previousNumber = null;

        foreach ($matches[0] as $token) {
            if (preg_match('/^\d/', $token)) {
                $previousNumber = (float) str_replace(',', '.', $token);
                $numbers[] = self::number($previousNumber);

                continue;
            }

            $word = self::canonicalWord($token, $dictionary);

            if ($previousNumber !== null && isset(self::UNIT_SCALES[$word])) {
                [$unit, $scale] = self::UNIT_SCALES[$word];
                array_pop($numbers);
                $numbers[] = self::number($previousNumber * $scale);
                $word = $unit;
            }

            $previousNumber = null;

            // Une abréviation du site peut se déplier en plusieurs mots :
            // « aas » = « acide acetylsalicylique ».
            foreach (explode(' ', $word) as $part) {
                if ($part === '' || in_array($part, self::STOP_WORDS, true)) {
                    continue;
                }

                $words[] = $part;
            }
        }

        return self::$cache[$value] = [$numbers, array_values(array_unique($words))];
    }

    /** @param  array<string, string>  $dictionary */
    private static function canonicalWord(string $token, array $dictionary): string
    {
        if (isset($dictionary[$token])) {
            return $dictionary[$token];
        }

        // Un pluriel simple : « gants » est « gant ». Les mots courts et ceux
        // en -ss, -us, -is ne changent pas (« sans », « virus »).
        if (strlen($token) >= 5 && str_ends_with($token, 's') && ! preg_match('/(ss|us|is)$/', $token)) {
            $stem = substr($token, 0, -1);

            return $dictionary[$stem] ?? $stem;
        }

        return $token;
    }

    private static function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * Les abréviations du site, réglées depuis le portail, l'emportent sur
     * celles de RIVO.
     *
     * @return array<string, string>
     */
    private static function dictionary(): array
    {
        $site = [];

        if (function_exists('app') && app()->bound(ProductSynonyms::class)) {
            $site = app(ProductSynonyms::class)->map();
        }

        return [...self::BUILT_IN_SYNONYMS, ...$site];
    }

    /**
     * Le multiensemble $needles est-il contenu dans $haystack ?
     *
     * @param  array<int, string>  $haystack
     * @param  array<int, string>  $needles
     */
    private static function contains(array $haystack, array $needles): bool
    {
        $available = array_count_values($haystack);

        foreach (array_count_values($needles) as $needle => $count) {
            if (($available[$needle] ?? 0) < $count) {
                return false;
            }
        }

        return true;
    }
}
