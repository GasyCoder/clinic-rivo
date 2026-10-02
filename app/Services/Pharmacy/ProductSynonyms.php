<?php

namespace App\Services\Pharmacy;

use App\Models\ProductSynonym;
use App\Support\ProductLabel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * ADR-241 — le dictionnaire des abréviations propre au site, lu une fois par
 * requête. ProductLabel l'ajoute à celui livré avec RIVO.
 */
class ProductSynonyms
{
    /** @var array<string, string>|null */
    private ?array $map = null;

    public function __construct()
    {
        // Les formes déjà calculées l'ont été avec un autre dictionnaire.
        ProductLabel::flush();
    }

    /** @return array<string, string> terme => forme canonique */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        try {
            if (! Schema::hasTable('product_synonyms')) {
                return $this->map = [];
            }

            return $this->map = ProductSynonym::query()
                ->pluck('canonical', 'term')
                ->mapWithKeys(fn (string $canonical, string $term) => [self::term($term) => self::canonical($canonical)])
                ->filter(fn (string $canonical, string $term) => $term !== '' && $canonical !== '')
                ->all();
        } catch (Throwable) {
            return $this->map = [];
        }
    }

    /** Le dictionnaire a changé : la prochaine lecture le relit. */
    public function forget(): void
    {
        $this->map = null;
        ProductLabel::flush();
    }

    /** Un terme : un seul mot, sans accent ni casse. */
    public static function term(?string $value): string
    {
        return Str::of((string) $value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    /** Une forme canonique : un ou plusieurs mots, sans accent ni casse. */
    public static function canonical(?string $value): string
    {
        return ProductLabel::normalize($value);
    }
}
