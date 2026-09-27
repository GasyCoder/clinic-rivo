<?php

namespace Tests\Unit;

use App\Support\ProductLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-181 — deux fournisseurs ne nomment pas un produit de la même façon.
 *
 * La règle ne décide jamais : elle demande. Mais une proposition fausse
 * ferait naître un prix d'achat sur le mauvais produit, et le stock suivrait.
 * Elle préfère donc se taire.
 */
class ProductLabelTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function sameProduct(): array
    {
        return [
            'accents, casse et ponctuation ignorés' => ['Alcool 1L 70°', 'ALCOOL 1l 70'],
            'un fournisseur précise, l’autre non' => ['Alcool 1l 70°', 'ALCOOL ETHYLIQUE 70% 1L'],
            'dose collée ou séparée' => ['Paracétamol 500mg', 'Paracétamol 500 mg comprimé boîte de 100'],
            'un conditionnement en plus' => ['Coton hydrophile', 'Coton hydrophile 500g'],
            'zéro de tête' => ['Compresse 05 cm', 'Compresse 5 cm'],
        ];
    }

    #[DataProvider('sameProduct')]
    public function test_two_labels_of_the_same_product_are_proposed(string $first, string $second): void
    {
        $this->assertTrue(ProductLabel::looksLikeSameProduct($first, $second), "« {$first} » et « {$second} »");
        // La ressemblance ne dépend pas du sens de lecture.
        $this->assertTrue(ProductLabel::looksLikeSameProduct($second, $first));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function differentProduct(): array
    {
        return [
            'un volume change' => ['Alcool 125ml 70°', 'Alcool 250ml 70°'],
            'une contenance change' => ['Seringue 5 ml', 'Seringue 10 ml'],
            'un calibre change' => ['Aiguille rose 18G', 'Aiguille épicrânienne 25G'],
            'un dosage change' => ['Paracétamol 500 mg', 'Paracétamol 1000 mg'],
            'une négation d’un seul côté' => ['Compresse stérile', 'Compresse non stérile'],
            'sans, d’un seul côté' => ['Gants poudrés', 'Gants sans poudre'],
            'une taille change' => ['Gants latex taille M', 'Gants latex taille L'],
            'un mot n’est pas un préfixe' => ['Alcool', 'Alcootest'],
            'rien de commun' => ['Paracétamol 500 mg', 'Amoxicilline 500 mg'],
            // Cas réel du 2026-09-24 : chacun dit ce que l'autre ne dit pas.
            'un nombre d’un côté, des mots de l’autre' => ['Alcool 125ml 70°', 'ALCOOL IODE SALICYLE IMRA 125ML'],
            'un libellé vide' => ['Alcool 1l', ''],
        ];
    }

    #[DataProvider('differentProduct')]
    public function test_two_labels_of_different_products_are_never_proposed(string $first, string $second): void
    {
        $this->assertFalse(ProductLabel::looksLikeSameProduct($first, $second), "« {$first} » et « {$second} »");
        $this->assertFalse(ProductLabel::looksLikeSameProduct($second, $first));
    }

    public function test_an_absent_label_is_never_a_match(): void
    {
        $this->assertFalse(ProductLabel::looksLikeSameProduct(null, null));
        $this->assertFalse(ProductLabel::looksLikeSameProduct(null, 'Alcool 1l'));
        $this->assertFalse(ProductLabel::looksLikeSameProduct('   ', 'Alcool 1l'));
    }

    /** Le groupement exact de l'ADR-098 n'a pas changé. */
    public function test_normalisation_still_ignores_accents_case_and_punctuation(): void
    {
        $this->assertSame('alcool 1l 70', ProductLabel::normalize('  Alcool   1L  70°  '));
        $this->assertSame(
            ProductLabel::normalize('Paracétamol 500 mg'),
            ProductLabel::normalize('PARACETAMOL  500   MG'),
        );
    }
}
