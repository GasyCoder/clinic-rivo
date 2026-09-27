<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Http\Controllers\Controller;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Services\Catalog\CatalogActor;
use App\Services\Laboratory\AnalysisCatalogHierarchy;
use App\Services\Laboratory\AnalysisCatalogImportService;
use App\Services\Laboratory\AnalysisCatalogManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalysisCatalogController extends Controller
{
    public function index(Request $request, AnalysisCatalogHierarchy $hierarchy): JsonResponse
    {
        $this->authorizeActor($request, 'analysis_catalog.view');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'ALL'])],
            'catalog_item' => ['nullable', 'uuid'],
        ]);
        $search = str($validated['q'] ?? '')->squish()->toString();
        $status = $validated['status'] ?? 'ALL';

        $hierarchyMetadata = $hierarchy->metadata();
        $analyses = AnalysisCatalog::query()
            ->with(['catalogItem:id,uuid,code,name', 'parent:id,uuid,code,designation'])
            ->when($status !== 'ALL', fn ($query) => $query->where('is_active', $status === 'ACTIVE'))
            ->when(filled($validated['catalog_item'] ?? null), fn ($query) => $query
                ->whereHas('catalogItem', fn ($catalog) => $catalog->where('uuid', $validated['catalog_item'])))
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

        return response()->json([
            'data' => [
                'analyses' => $analyses->map(fn (AnalysisCatalog $analysis) => $this->serialize(
                    $analysis,
                    $hierarchyMetadata[$analysis->id] ?? ['depth' => 0, 'path' => $analysis->designation],
                ))->values(),
                'catalog_items' => $this->laboratoryCatalogItems(),
                'parents' => $hierarchy->parentOptions(),
                'levels' => AnalysisCatalog::LEVELS,
                'result_types' => AnalysisCatalog::RESULT_TYPES,
                'exam_categories' => AnalysisCatalog::query()
                    ->whereNotNull('exam_category')
                    ->distinct()
                    ->orderBy('exam_category')
                    ->pluck('exam_category'),
            ],
            'meta' => [
                'site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')],
                'summary' => [
                    'displayed' => $analyses->count(),
                    'active' => AnalysisCatalog::query()->where('is_active', true)->count(),
                    'inactive' => AnalysisCatalog::query()->where('is_active', false)->count(),
                    'services' => count($this->laboratoryCatalogItems()),
                    'nested_groups' => AnalysisCatalog::query()
                        ->where('level', AnalysisCatalog::CONTAINER_LEVEL)
                        ->whereNotNull('parent_id')
                        ->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $analysisUuid): JsonResponse
    {
        $this->authorizeActor($request, 'analysis_catalog.view');
        $analysis = AnalysisCatalog::query()->with(['catalogItem', 'parent'])->where('uuid', $analysisUuid)->firstOrFail();

        return response()->json([
            'data' => [
                ...$this->serialize($analysis),
                'children' => $analysis->children()
                    ->orderBy('display_order')->orderBy('designation')
                    ->get()
                    ->map(fn (AnalysisCatalog $child) => $this->serializeWithChildren($child, 1))
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request, AnalysisCatalogManager $manager): JsonResponse
    {
        $actor = $this->actor($request, 'analysis_catalog.create');
        $analysis = $manager->saveWithChildren(null, $request->validate($this->rules()), $actor);

        return response()->json([
            'message' => "Analyse « {$analysis->designation} » ajoutée sur le site.",
            'data' => $this->serialize($analysis->load(['catalogItem', 'parent'])),
        ], 201);
    }

    public function update(
        Request $request,
        string $analysisUuid,
        AnalysisCatalogManager $manager,
    ): JsonResponse {
        $actor = $this->actor($request, 'analysis_catalog.update');
        $analysis = AnalysisCatalog::query()->where('uuid', $analysisUuid)->firstOrFail();
        $analysis = $manager->saveWithChildren($analysis, $request->validate($this->rules($analysis)), $actor);

        return response()->json([
            'message' => "Analyse « {$analysis->designation} » mise à jour sur le site.",
            'data' => $this->serialize($analysis),
        ]);
    }

    public function activate(Request $request, string $analysisUuid, AnalysisCatalogManager $manager): JsonResponse
    {
        $actor = $this->actor($request, 'analysis_catalog.activate');
        $analysis = AnalysisCatalog::query()->where('uuid', $analysisUuid)->firstOrFail();

        return response()->json([
            'message' => 'Analyse activée sur le site.',
            'data' => $this->serialize($manager->setActive($analysis, true, $actor)->load(['catalogItem', 'parent'])),
        ]);
    }

    public function deactivate(Request $request, string $analysisUuid, AnalysisCatalogManager $manager): JsonResponse
    {
        $actor = $this->actor($request, 'analysis_catalog.deactivate');
        $analysis = AnalysisCatalog::query()->where('uuid', $analysisUuid)->firstOrFail();

        return response()->json([
            'message' => 'Analyse désactivée sur le site.',
            'data' => $this->serialize($manager->setActive($analysis, false, $actor)->load(['catalogItem', 'parent'])),
        ]);
    }

    public function import(Request $request, AnalysisCatalogImportService $importer): JsonResponse
    {
        $actor = $this->actor($request, 'analysis_catalog.import');
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*' => ['required', 'array'],
        ]);
        $result = $importer->import($validated['rows'], $actor);

        return response()->json([
            'message' => "Import terminé : {$result['created']} créée(s), {$result['updated']} mise(s) à jour.",
            'data' => $result,
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(?AnalysisCatalog $ignore = null): array
    {
        return [
            'catalog_item_uuid' => [
                'required', 'uuid',
                Rule::exists('catalog_items', 'uuid')->where(fn ($query) => $query
                    ->where('type', CatalogItemType::Service->value)
                    ->where('module', CatalogModule::Laboratory->value)
                    ->whereNull('deleted_at')),
            ],
            'parent_uuid' => ['nullable', 'uuid', Rule::exists('analysis_catalogs', 'uuid')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:80', Rule::unique('analysis_catalogs', 'code')->ignore($ignore?->getKey())],
            'level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'designation' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'exam_category' => ['nullable', 'string', 'max:100'],
            'result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'reference_general' => ['nullable', 'string', 'max:255'],
            'reference_male' => ['nullable', 'string', 'max:255'],
            'reference_female' => ['nullable', 'string', 'max:255'],
            'reference_child_male' => ['nullable', 'string', 'max:255'],
            'reference_child_female' => ['nullable', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:60'],
            'predefined_values' => ['nullable', 'array', 'max:30'],
            'predefined_values.*' => ['required', 'string', 'max:100', 'distinct'],
            'display_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['required', 'boolean'],
            'is_bold' => ['sometimes', 'boolean'],
            'children' => ['sometimes', 'array', 'max:60'],
            'children.*.uuid' => ['nullable', 'uuid'],
            'children.*.code' => ['required', 'string', 'max:80'],
            'children.*.level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'children.*.designation' => ['required', 'string', 'max:255'],
            'children.*.description' => ['nullable', 'string', 'max:2000'],
            'children.*.exam_category' => ['nullable', 'string', 'max:100'],
            'children.*.result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'children.*.reference_general' => ['nullable', 'string', 'max:255'],
            'children.*.reference_male' => ['nullable', 'string', 'max:255'],
            'children.*.reference_female' => ['nullable', 'string', 'max:255'],
            'children.*.reference_child_male' => ['nullable', 'string', 'max:255'],
            'children.*.reference_child_female' => ['nullable', 'string', 'max:255'],
            'children.*.unit' => ['nullable', 'string', 'max:60'],
            'children.*.predefined_values' => ['nullable', 'array', 'max:30'],
            'children.*.predefined_values.*' => ['required', 'string', 'max:100', 'distinct'],
            'children.*.display_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'children.*.is_active' => ['sometimes', 'boolean'],
            'children.*.is_bold' => ['sometimes', 'boolean'],
            // A sub-analysis can itself be a group with its own sub-analyses
            // (grandchildren) — the inline editor goes exactly one level
            // deeper than children.*; a third level still goes through the
            // normal parent picker as a separate entry.
            'children.*.children' => ['sometimes', 'array', 'max:60'],
            'children.*.children.*.uuid' => ['nullable', 'uuid'],
            'children.*.children.*.code' => ['required', 'string', 'max:80'],
            'children.*.children.*.level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'children.*.children.*.designation' => ['required', 'string', 'max:255'],
            'children.*.children.*.description' => ['nullable', 'string', 'max:2000'],
            'children.*.children.*.exam_category' => ['nullable', 'string', 'max:100'],
            'children.*.children.*.result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'children.*.children.*.reference_general' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_male' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_female' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_child_male' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_child_female' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.unit' => ['nullable', 'string', 'max:60'],
            'children.*.children.*.predefined_values' => ['nullable', 'array', 'max:30'],
            'children.*.children.*.predefined_values.*' => ['required', 'string', 'max:100', 'distinct'],
            'children.*.children.*.display_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'children.*.children.*.is_active' => ['sometimes', 'boolean'],
            'children.*.children.*.is_bold' => ['sometimes', 'boolean'],
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

    /**
     * Serializes a sub-analysis for the inline editor together with its own
     * children, when it is itself a group — the editor goes exactly one
     * level deeper than the direct children already listed by show(), so
     * $remainingDepth is always called with 1 from there.
     *
     * @return array<string, mixed>
     */
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
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(AnalysisCatalog $item, ?array $hierarchy = null): array
    {
        return [
            'uuid' => $item->uuid,
            'code' => $item->code,
            'level' => $item->level,
            'designation' => $item->designation,
            'description' => $item->description,
            'exam_category' => $item->exam_category,
            'result_type' => $item->result_type,
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

    private function actor(Request $request, string $permission): CatalogActor
    {
        $actor = CatalogActor::fromRemoteRequest($request);
        if ($actor->cannot($permission)) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }

        return $actor;
    }

    private function authorizeActor(Request $request, string $permission): void
    {
        $this->actor($request, $permission);
    }
}
