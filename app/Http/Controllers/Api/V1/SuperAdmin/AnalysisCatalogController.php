<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\LabEntryMode;
use App\Http\Controllers\Controller;
use App\Models\AnalysisCatalog;
use App\Services\Catalog\CatalogActor;
use App\Services\Laboratory\AnalysisCatalogDirectory;
use App\Services\Laboratory\AnalysisCatalogImportService;
use App\Services\Laboratory\AnalysisCatalogManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalysisCatalogController extends Controller
{
    public function index(Request $request, AnalysisCatalogDirectory $directory): JsonResponse
    {
        $this->authorizeActor($request, 'analysis_catalog.view');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(AnalysisCatalogDirectory::STATUSES)],
            'catalog_item' => ['nullable', 'uuid'],
        ]);

        return response()->json($directory->listing(
            $validated['q'] ?? '',
            $validated['status'] ?? 'ALL',
            $validated['catalog_item'] ?? null,
        ));
    }

    public function show(Request $request, string $analysisUuid, AnalysisCatalogDirectory $directory): JsonResponse
    {
        $this->authorizeActor($request, 'analysis_catalog.view');
        $analysis = AnalysisCatalog::query()->where('uuid', $analysisUuid)->firstOrFail();

        return response()->json(['data' => $directory->detail($analysis)]);
    }

    public function store(Request $request, AnalysisCatalogManager $manager): JsonResponse
    {
        $actor = $this->actor($request, 'analysis_catalog.create');
        $analysis = $manager->saveWithChildren(null, $request->validate($this->rules()), $actor);

        return response()->json([
            'message' => "Analyse « {$analysis->designation} » ajoutée sur le site.",
            'data' => app(AnalysisCatalogDirectory::class)->serialize($analysis->load(['catalogItem', 'parent'])),
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
            'data' => app(AnalysisCatalogDirectory::class)->serialize($analysis),
        ]);
    }

    public function activate(Request $request, string $analysisUuid, AnalysisCatalogManager $manager): JsonResponse
    {
        $actor = $this->actor($request, 'analysis_catalog.activate');
        $analysis = AnalysisCatalog::query()->where('uuid', $analysisUuid)->firstOrFail();

        return response()->json([
            'message' => 'Analyse activée sur le site.',
            'data' => app(AnalysisCatalogDirectory::class)->serialize($manager->setActive($analysis, true, $actor)->load(['catalogItem', 'parent'])),
        ]);
    }

    public function deactivate(Request $request, string $analysisUuid, AnalysisCatalogManager $manager): JsonResponse
    {
        $actor = $this->actor($request, 'analysis_catalog.deactivate');
        $analysis = AnalysisCatalog::query()->where('uuid', $analysisUuid)->firstOrFail();

        return response()->json([
            'message' => 'Analyse désactivée sur le site.',
            'data' => app(AnalysisCatalogDirectory::class)->serialize($manager->setActive($analysis, false, $actor)->load(['catalogItem', 'parent'])),
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
            'entry_mode' => ['nullable', Rule::in(LabEntryMode::values())],
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
            'children.*.entry_mode' => ['nullable', Rule::in(LabEntryMode::values())],
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
            'children.*.children.*.entry_mode' => ['nullable', Rule::in(LabEntryMode::values())],
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
