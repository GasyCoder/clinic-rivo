<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\ImportAnalysisCatalogRequest;
use App\Http\Requests\Administration\StoreAnalysisCatalogRequest;
use App\Http\Requests\Administration\UpdateAnalysisCatalogRequest;
use App\Models\AnalysisCatalog;
use App\Services\Laboratory\AnalysisCatalogDirectory;
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
    /**
     * Le même écran que le portail (ADR-063, amendement du 2026-09-28) : un
     * seul site, le sien, lu directement ; le portail lit le même catalogue
     * par l'API de chaque site.
     */
    public function index(Request $request, AnalysisCatalogDirectory $directory): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(AnalysisCatalogDirectory::STATUSES)],
            'catalog_item' => ['nullable', 'uuid'],
        ]);
        $filters['status'] ??= 'ALL';
        $site = $this->siteMeta();

        return Inertia::render('Analyses/Index', [
            'context' => ['mode' => 'site'],
            // La prestation se choisit à l'écran, parmi toutes, comme au portail.
            'sites' => [[
                'site' => $site,
                'ok' => true,
                'message' => null,
                ...$directory->listing($filters['q'] ?? '', $filters['status']),
            ]],
            'filters' => $filters,
            'selectedSiteCode' => $site['code'],
        ]);
    }

    public function create(AnalysisCatalogDirectory $directory): Response
    {
        return Inertia::render('Analyses/Create', [
            'context' => ['mode' => 'site'],
            'clinicSite' => $this->siteMeta(),
            ...$this->formData($directory),
        ]);
    }

    public function edit(Request $request, AnalysisCatalog $analysisCatalog, AnalysisCatalogDirectory $directory): Response
    {
        return Inertia::render('Analyses/Edit', [
            'context' => ['mode' => 'site'],
            'clinicSite' => $this->siteMeta(),
            ...$this->formData($directory),
            'analysis' => $directory->detail($analysisCatalog),
            'initialStep' => AnalysisCatalogDirectory::formStep($request->query('etape')),
        ]);
    }

    /**
     * ADR-063, amendement du 2026-10-01 — la création ne demande que l'identité
     * de l'analyse, puis ouvre sa fiche (`after=edit`), où la suite s'enregistre
     * toute seule. Sans `after`, l'ancien retour au catalogue reste valable.
     */
    public function store(StoreAnalysisCatalogRequest $request, AnalysisCatalogManager $manager): RedirectResponse
    {
        $analysis = $manager->saveWithChildren(null, $request->validated(), $request->user());

        if ($request->input('after') === 'edit') {
            return to_route('administration.analyses.edit', [$analysis, 'etape' => 'resultat'])
                ->with('status', "Analyse « {$analysis->designation} » créée : la suite s’enregistre toute seule.");
        }

        return to_route('administration.analyses.index')->with('status', "Analyse « {$analysis->designation} » ajoutée.");
    }

    /** Un enregistrement automatique (`_autosave`) revient sur la fiche, sans message. */
    public function update(UpdateAnalysisCatalogRequest $request, AnalysisCatalog $analysisCatalog, AnalysisCatalogManager $manager): RedirectResponse
    {
        $analysis = $manager->saveWithChildren($analysisCatalog, $request->validated(), $request->user());

        if ($request->boolean('_autosave')) {
            return back();
        }

        return to_route('administration.analyses.index')->with('status', "Analyse « {$analysis->designation} » mise à jour.");
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

    public function export(Request $request, ExcelWorkbook $excel, AnalysisCatalogDirectory $directory): StreamedResponse
    {
        $status = in_array($request->query('status'), AnalysisCatalogDirectory::STATUSES, true)
            ? $request->query('status')
            : 'ALL';

        return $excel->download(
            'catalogue-analyses-'.now()->format('Y-m-d-His'),
            'Catalogue analyses',
            AnalysisCatalogImportService::HEADERS,
            collect($directory->listing('', $status)['data']['analyses'])
                ->map(fn (array $item) => $directory->exportRow($item)),
        );
    }

    public function template(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'modele-import-catalogue-analyses',
            'Analyses à importer',
            AnalysisCatalogImportService::HEADERS,
            AnalysisCatalogDirectory::templateRows(),
        );
    }

    public function import(ImportAnalysisCatalogRequest $request, ExcelWorkbook $excel, AnalysisCatalogImportService $importer): RedirectResponse
    {
        $result = $importer->import($excel->rows($request->file('file')), $request->user());

        return back()->with('status', "Import terminé : {$result['created']} créée(s), {$result['updated']} mise(s) à jour.");
    }

    /** @return array{code: string, name: string} */
    private function siteMeta(): array
    {
        return ['code' => (string) config('rivo.site.code'), 'name' => (string) config('rivo.site.name')];
    }

    /** @return array<string, mixed> */
    private function formData(AnalysisCatalogDirectory $directory): array
    {
        $options = $directory->formOptions();

        return [
            'catalogItems' => $options['catalog_items'],
            'parents' => $options['parents'],
            'levels' => $options['levels'],
            'resultTypes' => $options['result_types'],
            'entryModes' => $options['entry_modes'],
            'examCategories' => $options['exam_categories'],
        ];
    }
}
