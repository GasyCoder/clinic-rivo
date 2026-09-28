<?php

namespace App\Services\Laboratory;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\LabEntryMode;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;

/**
 * Lecture du catalogue des analyses d'un site, écrite une seule fois.
 *
 * L'écran du site (/administration/analyses) et l'API lue par le portail
 * (/api/v1/super-admin/analysis-catalogs) servent exactement la même forme :
 * les deux affichent désormais la même page (ADR-063, amendement du
 * 2026-09-28), et deux copies de cette lecture finiraient par diverger.
 */
class AnalysisCatalogDirectory
{
    public const STATUSES = ['ACTIVE', 'INACTIVE', 'ALL'];

    public function __construct(private readonly AnalysisCatalogHierarchy $hierarchy) {}

    /**
     * La liste d'un site et ce qu'il faut pour la lire : prestations, groupes,
     * niveaux, types de résultat, catégories, et les compteurs.
     *
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function listing(string $search = '', string $status = 'ALL', ?string $catalogItemUuid = null): array
    {
        $search = str($search)->squish()->toString();
        $metadata = $this->hierarchy->metadata();

        $analyses = AnalysisCatalog::query()
            ->with(['catalogItem:id,uuid,code,name', 'parent:id,uuid,code,designation'])
            ->when($status !== 'ALL', fn ($query) => $query->where('is_active', $status === 'ACTIVE'))
            ->when(filled($catalogItemUuid), fn ($query) => $query
                ->whereHas('catalogItem', fn ($catalog) => $catalog->where('uuid', $catalogItemUuid)))
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('code', 'like', "%{$search}%")
                ->orWhere('designation', 'like', "%{$search}%")
                ->orWhereHas('catalogItem', fn ($catalog) => $catalog
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))))
            ->orderBy('catalog_item_id')
            ->orderBy('display_order')
            ->orderBy('designation')
            ->limit(1000)
            ->get();

        $options = $this->formOptions();

        return [
            'data' => [
                'analyses' => $analyses->map(fn (AnalysisCatalog $analysis) => $this->serialize(
                    $analysis,
                    $metadata[$analysis->id] ?? null,
                ))->values()->all(),
                ...$options,
            ],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'summary' => [
                    'displayed' => $analyses->count(),
                    'active' => AnalysisCatalog::query()->where('is_active', true)->count(),
                    'inactive' => AnalysisCatalog::query()->where('is_active', false)->count(),
                    'services' => count($options['catalog_items']),
                    'nested_groups' => AnalysisCatalog::query()
                        ->where('level', AnalysisCatalog::CONTAINER_LEVEL)
                        ->whereNotNull('parent_id')
                        ->count(),
                ],
            ],
        ];
    }

    /**
     * Ce que le formulaire d'une analyse propose, en snake_case comme l'API.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return [
            'catalog_items' => $this->laboratoryCatalogItems(),
            'parents' => $this->hierarchy->parentOptions(),
            'levels' => AnalysisCatalog::LEVELS,
            'result_types' => AnalysisCatalog::RESULT_TYPES,
            'entry_modes' => LabEntryMode::options(),
            'exam_categories' => AnalysisCatalog::query()
                ->whereNotNull('exam_category')
                ->distinct()
                ->orderBy('exam_category')
                ->pluck('exam_category')
                ->all(),
        ];
    }

    /**
     * Une analyse avec ses sous-analyses, et les leurs quand elles sont
     * elles-mêmes des groupes : l'éditeur descend d'un niveau de plus.
     *
     * @return array<string, mixed>
     */
    public function detail(AnalysisCatalog $analysis): array
    {
        $analysis->loadMissing(['catalogItem', 'parent']);

        return [
            ...$this->serialize($analysis, $this->hierarchy->metadata()[$analysis->id] ?? null),
            'children' => $analysis->children()
                ->orderBy('display_order')->orderBy('designation')
                ->get()
                ->map(fn (AnalysisCatalog $child) => $this->serializeWithChildren($child, 1))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function serialize(AnalysisCatalog $item, ?array $hierarchy = null): array
    {
        return [
            'uuid' => $item->uuid,
            'code' => $item->code,
            'level' => $item->level,
            'designation' => $item->designation,
            'description' => $item->description,
            'exam_category' => $item->exam_category,
            'result_type' => $item->result_type,
            'entry_mode' => $item->entry_mode,
            'reference_general' => $item->reference_general,
            'reference_male' => $item->reference_male,
            'reference_female' => $item->reference_female,
            'reference_child_male' => $item->reference_child_male,
            'reference_child_female' => $item->reference_child_female,
            'unit' => $item->unit,
            'predefined_values' => $item->predefined_values ?? [],
            'display_order' => $item->display_order,
            'is_active' => $item->is_active,
            'is_bold' => $item->is_bold,
            'catalog_item' => $item->catalogItem,
            'parent' => $item->parent,
            'updated_at' => $item->updated_at?->toIso8601String(),
            'source_system' => $item->source_system,
            'source_metadata' => $item->source_metadata,
            'hierarchy_depth' => $hierarchy['depth'] ?? 0,
            'hierarchy_path' => $hierarchy['path'] ?? $item->designation,
        ];
    }

    /** @return array<int, mixed> Une ligne de l'export, aux colonnes du modèle d'import. */
    public function exportRow(array $item): array
    {
        return [
            data_get($item, 'catalog_item.code'),
            $item['code'],
            $item['level'],
            data_get($item, 'parent.code'),
            $item['designation'],
            $item['description'],
            $item['result_type'],
            $item['reference_general'],
            $item['reference_male'],
            $item['reference_female'],
            $item['reference_child_male'],
            $item['reference_child_female'],
            $item['unit'],
            implode('|', $item['predefined_values'] ?? []),
            $item['display_order'],
            $item['is_active'] ? 'ACTIVE' : 'INACTIVE',
        ];
    }

    /** @return array<int, array<int, mixed>> Les deux lignes d'exemple du modèle d'import. */
    public static function templateRows(): array
    {
        return [
            ['LAB-NFS', 'NFS-HB', 'CHILD', 'NFS', 'Hémoglobine', '', 'NUMERIC', '', '13–17', '12–16', '', '', 'g/dL', '', 10, 'ACTIVE'],
            ['LAB-GROUP-RH', 'GROUP-RH', 'NORMAL', '', 'Groupe sanguin et Rhésus', '', 'CHOICE', '', '', '', '', '', '', 'A+|A-|B+|B-|AB+|AB-|O+|O-', 20, 'ACTIVE'],
        ];
    }

    /** @return array<string, mixed> */
    private function serializeWithChildren(AnalysisCatalog $item, int $remainingDepth): array
    {
        return [
            ...$this->serialize($item),
            'children' => $remainingDepth > 0 && $item->level === AnalysisCatalog::CONTAINER_LEVEL
                ? $item->children()
                    ->orderBy('display_order')->orderBy('designation')
                    ->get()
                    ->map(fn (AnalysisCatalog $child) => $this->serializeWithChildren($child, $remainingDepth - 1))
                    ->values()
                    ->all()
                : [],
        ];
    }

    /** @return array<int, array<string, string>> */
    private function laboratoryCatalogItems(): array
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Laboratory->value)
            ->orderBy('name')
            ->get(['uuid', 'code', 'name'])
            ->map(fn (CatalogItem $item) => ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name])
            ->all();
    }
}
