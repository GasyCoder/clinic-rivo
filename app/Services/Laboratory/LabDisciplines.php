<?php

namespace App\Services\Laboratory;

use App\Models\AnalysisCatalog;
use App\Models\LabDiscipline;
use Illuminate\Support\Str;

/**
 * ADR-214 — la discipline (Hématologie, Biochimie…) d'une prestation du
 * Laboratoire, lue sur le catalogue des analyses (`exam_category`) : c'est elle
 * qui range la feuille de paillasse, comme les « examens » du laboratoire de la
 * clinique. Une prestation sans discipline au catalogue tombe dans « Sans
 * discipline » : rien n'est deviné depuis son nom.
 *
 * ADR-238 — `exam_category` est désormais la copie du nom d'une discipline du
 * référentiel (`lab_disciplines`), qui porte aussi l'ordre des feuilles et des
 * sections du compte rendu.
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

    /**
     * Une clé de tri : l'ordre du référentiel, puis le nom ; « Sans discipline »
     * toujours en dernier.
     *
     * @return callable(string): array<int, mixed>
     */
    public function sorter(): callable
    {
        $order = LabDiscipline::withTrashed()
            ->get(['name', 'display_order'])
            ->mapWithKeys(fn (LabDiscipline $discipline) => [self::label($discipline->name) => $discipline->display_order])
            ->all();

        return fn (string $label): array => [$label === self::NONE ? 1 : 0, $order[$label] ?? PHP_INT_MAX, $label];
    }

    /** « HEMATOLOGIE » et « Hématologie » se lisent pareil : une seule feuille. */
    public static function label(string $category): string
    {
        $text = Str::squish($category);

        return $text === '' ? self::NONE : Str::ucfirst(Str::lower($text));
    }
}
