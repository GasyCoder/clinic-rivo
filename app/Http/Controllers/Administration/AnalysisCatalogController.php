<?php

namespace App\Http\Controllers\Administration;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ImportAnalysisCatalogRequest;
use App\Http\Requests\Administration\StoreAnalysisCatalogRequest;
use App\Http\Requests\Administration\UpdateAnalysisCatalogRequest;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Services\Laboratory\AnalysisCatalogImportService;
use App\Services\Laboratory\AnalysisCatalogManager;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisCatalogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'ALL'])],
            'catalog_item' => ['nullable', 'uuid'],
        ]);
        $search = str($filters['q'] ?? '')->squish()->toString();
        $status = $filters['status'] ?? 'ACTIVE';

        $analyses = AnalysisCatalog::query()
            ->with(['catalogItem:id,uuid,code,name', 'parent:id,uuid,code,designation'])
            ->when($status !== 'ALL', fn ($query) => $query->where('is_active', $status === 'ACTIVE'))
            ->when(filled($filters['catalog_item'] ?? null), fn ($query) => $query
                ->whereHas('catalogItem', fn ($catalog) => $catalog->where('uuid', $filters['catalog_item'])))
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('code', 'like', "%{$search}%")
                ->orWhere('designation', 'like', "%{$search}%")
                ->orWhereHas('catalogItem', fn ($catalog) => $catalog
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))))
            ->orderBy('catalog_item_id')
            ->orderBy('display_order')
            ->orderBy('designation')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AnalysisCatalog $analysis) => $this->serialize($analysis));

        return Inertia::render('Administration/Analyses/Index', [
            'analyses' => $analyses,
            'catalogItems' => $this->laboratoryCatalogItems(),
            'parents' => AnalysisCatalog::query()
                ->where('level', 'PARENT')->where('is_active', true)
                ->with('catalogItem:id,uuid')
                ->orderBy('designation')->get()
                ->map(fn (AnalysisCatalog $item) => [
                    'uuid' => $item->uuid,
                    'catalog_item_uuid' => $item->catalogItem->uuid,
                    'code' => $item->code,
                    'designation' => $item->designation,
                ]),
            'filters' => ['q' => $search, 'status' => $status, 'catalog_item' => $filters['catalog_item'] ?? ''],
            'summary' => [
                'active' => AnalysisCatalog::query()->where('is_active', true)->count(),
                'inactive' => AnalysisCatalog::query()->where('is_active', false)->count(),
                'services' => CatalogItem::query()->where('module', CatalogModule::Laboratory->value)->count(),
            ],
            'levels' => AnalysisCatalog::LEVELS,
            'resultTypes' => AnalysisCatalog::RESULT_TYPES,
        ]);
    }

    public function store(StoreAnalysisCatalogRequest $request, AnalysisCatalogManager $manager): RedirectResponse
    {
        $analysis = $manager->create($request->validated(), $request->user());

        return back()->with('status', "Analyse « {$analysis->designation} » ajoutée.");
    }

    public function update(UpdateAnalysisCatalogRequest $request, AnalysisCatalog $analysisCatalog, AnalysisCatalogManager $manager): RedirectResponse
    {
        $analysis = $manager->update($analysisCatalog, $request->validated(), $request->user());

        return back()->with('status', "Analyse « {$analysis->designation} » mise à jour.");
    }

    public function activate(Request $request, AnalysisCatalog $analysisCatalog, AnalysisCatalogManager $manager): RedirectResponse
    {
        $manager->setActive($analysisCatalog, true, $request->user());

        return back()->with('status', 'Analyse activée.');
    }

    public function deactivate(Request $request, AnalysisCatalog $analysisCatalog, AnalysisCatalogManager $manager): RedirectResponse
    {
        $manager->setActive($analysisCatalog, false, $request->user());

        return back()->with('status', 'Analyse désactivée.');
    }

    public function export(Request $request, ExcelWorkbook $excel): StreamedResponse
    {
        $query = AnalysisCatalog::query()->with(['catalogItem:id,code', 'parent:id,code'])->orderBy('display_order');
        if ($request->query('status') === 'ACTIVE') {
            $query->where('is_active', true);
        }
        if ($request->query('status') === 'INACTIVE') {
            $query->where('is_active', false);
        }

        return $excel->download(
            'catalogue-analyses-'.now()->format('Y-m-d-His'),
            'Catalogue analyses',
            AnalysisCatalogImportService::HEADERS,
            $query->get()->map(fn (AnalysisCatalog $item) => $this->exportRow($item)),
        );
    }

    public function template(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'modele-import-catalogue-analyses',
            'Analyses à importer',
            AnalysisCatalogImportService::HEADERS,
            [
                ['LAB-NFS', 'NFS-HB', 'CHILD', 'NFS', 'Hémoglobine', '', 'NUMERIC', '', '13–17', '12–16', '', '', 'g/dL', '', 10, 'ACTIVE'],
                ['LAB-GROUP-RH', 'GROUP-RH', 'NORMAL', '', 'Groupe sanguin et Rhésus', '', 'CHOICE', '', '', '', '', '', '', 'A+|A-|B+|B-|AB+|AB-|O+|O-', 20, 'ACTIVE'],
            ],
        );
    }

    public function import(ImportAnalysisCatalogRequest $request, ExcelWorkbook $excel, AnalysisCatalogImportService $importer): RedirectResponse
    {
        $result = $importer->import($excel->rows($request->file('file')), $request->user());

        return back()->with('status', "Import terminé : {$result['created']} créée(s), {$result['updated']} mise(s) à jour.");
    }

    /** @return array<int, array<string, string>> */
    private function laboratoryCatalogItems(): array
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Laboratory->value)
            ->orderBy('name')->get(['uuid', 'code', 'name'])
            ->map(fn (CatalogItem $item) => ['uuid' => $item->uuid, 'code' => $item->code, 'name' => $item->name])
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialize(AnalysisCatalog $item): array
    {
        return [
            'uuid' => $item->uuid, 'code' => $item->code, 'level' => $item->level,
            'designation' => $item->designation, 'description' => $item->description,
            'result_type' => $item->result_type, 'reference_general' => $item->reference_general,
            'reference_male' => $item->reference_male, 'reference_female' => $item->reference_female,
            'reference_child_male' => $item->reference_child_male,
            'reference_child_female' => $item->reference_child_female,
            'unit' => $item->unit, 'predefined_values' => $item->predefined_values ?? [],
            'display_order' => $item->display_order, 'is_active' => $item->is_active,
            'catalog_item' => $item->catalogItem,
            'parent' => $item->parent,
        ];
    }

    /** @return array<int, mixed> */
    private function exportRow(AnalysisCatalog $item): array
    {
        return [
            $item->catalogItem->code, $item->code, $item->level, $item->parent?->code,
            $item->designation, $item->description, $item->result_type,
            $item->reference_general, $item->reference_male, $item->reference_female,
            $item->reference_child_male, $item->reference_child_female, $item->unit,
            implode('|', $item->predefined_values ?? []), $item->display_order,
            $item->is_active ? 'ACTIVE' : 'INACTIVE',
        ];
    }
}
