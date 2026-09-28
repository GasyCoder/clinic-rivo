<?php

namespace App\Services\Laboratory;

use App\Models\AnalysisCatalog;
use Illuminate\Support\Str;

/**
 * ADR-214 — la discipline (Hématologie, Biochimie…) d'une prestation du
 * Laboratoire, lue sur le catalogue des analyses (`exam_category`) : c'est elle
 * qui range la feuille de paillasse, comme les « examens » du laboratoire de la
 * clinique. Une prestation sans discipline au catalogue tombe dans « Sans
 * discipline » : rien n'est deviné depuis son nom.
 */
class LabDisciplines
{
    public const NONE = 'Sans discipline';

    /**
     * @param  array<int, int>  $catalogItemIds
     * @return array<int, string> catalog_item_id → discipline
     */
    public function forCatalogItems(array $catalogItemIds): array
    {
        if ($catalogItemIds === []) {
            return [];
        }

        return AnalysisCatalog::query()
            ->whereIn('catalog_item_id', array_values(array_unique($catalogItemIds)))
            ->whereNotNull('exam_category')
            ->where('exam_category', '!=', '')
            ->orderByRaw('parent_id IS NOT NULL')
            ->orderBy('display_order')
            ->get(['catalog_item_id', 'exam_category'])
            ->groupBy('catalog_item_id')
            ->map(fn ($rows) => self::label((string) $rows->first()->exam_category))
            ->all();
    }

    /** « HEMATOLOGIE » et « Hématologie » se lisent pareil : une seule feuille. */
    public static function label(string $category): string
    {
        $text = Str::squish($category);

        return $text === '' ? self::NONE : Str::ucfirst(Str::lower($text));
    }
}
