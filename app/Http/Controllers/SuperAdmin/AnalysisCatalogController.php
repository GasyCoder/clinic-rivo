<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\LabEntryMode;
use App\Http\Controllers\Controller;
use App\Models\AnalysisCatalog;
use App\Services\Laboratory\AnalysisCatalogDirectory;
use App\Services\Laboratory\AnalysisCatalogImportService;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\Laboratory\LabCriticalRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalysisCatalogController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'ALL'])],
            'catalog_item' => ['nullable', 'uuid'],
            'site' => ['nullable', 'string', 'max:10'],
        ]);
        $filters['status'] ??= 'ALL';
        // Le site ouvert : celui d'où l'on vient (les tarifs y renvoient), s'il existe.
        $site = mb_strtoupper(trim((string) ($filters['site'] ?? '')));
        $siteCodes = collect(config('rivo.clinics', []))->pluck('code')->all();

        return Inertia::render('Analyses/Index', [
            'context' => ['mode' => 'portal'],
            // La prestation se choisit à l'écran, parmi toutes : l'envoyer au site
            // lui ferait servir une liste réduite à elle seule.
            'sites' => $client->analysisCatalogsForAllSites(
                $request->user(),
                array_filter(Arr::only($filters, ['q', 'status'])),
            ),
            'filters' => Arr::except($filters, ['site']),
            'selectedSiteCode' => in_array($site, $siteCodes, true) ? $site : null,
        ]);
    }

    public function create(Request $request, string $site, PortalSiteApiClient $client): Response
    {
        return Inertia::render('Analyses/Create', [
            'context' => ['mode' => 'portal'],
            // Never name this prop "site" — HandleInertiaRequests already
            // shares a global "site" prop describing THIS deployment (the
            // portal itself, incl. site.type === 'admin', which Menu.vue
            // reads to pick the portal navigation). A page-level prop with
            // the same key silently shadows it, and the whole layout starts
            // rendering as if it were the clinic site instead of the portal.
            'clinicSite' => $this->siteMeta($site),
            ...$this->formData($site, $request, $client),
        ]);
    }

    public function edit(Request $request, string $site, string $analysis, PortalSiteApiClient $client): Response
    {
        $detail = $client->analysisDetail($site, $analysis, $request->user());
        abort_unless($detail['ok'], 503, $detail['message'] ?? 'Le site ne répond pas actuellement.');

        return Inertia::render('Analyses/Edit', [
            'context' => ['mode' => 'portal'],
            // See create() above: must not be named "site".
            'clinicSite' => $this->siteMeta($site),
            'analysis' => $detail['data'],
            ...$this->formData($site, $request, $client),
            'initialStep' => AnalysisCatalogDirectory::formStep($request->query('etape')),
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate(['site_code' => $this->siteCodeRules(), ...$this->catalogRules()]);
        $site = $validated['site_code'];
        unset($validated['site_code']);
        $result = $client->createAnalysis($site, $validated, $request->user());

        // ADR-063, amendement du 2026-10-01 — la fiche créée s'ouvre aussitôt ;
        // la suite s'y enregistre toute seule, toujours par l'API du site.
        $uuid = $result['data']['uuid'] ?? null;
        if ($result['ok'] && $request->input('after') === 'edit' && is_string($uuid)) {
            return to_route('super-admin.analyses.edit', ['site' => $site, 'analysis' => $uuid, 'etape' => 'resultat'])
                ->with('status', ($result['message'] ?? null) ?: 'Analyse créée sur le site : la suite s’enregistre toute seule.');
        }

        return $this->respond($result, 'Analyse ajoutée au site.');
    }

    public function update(
        Request $request,
        string $site,
        string $analysis,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $validated = $request->validate($this->catalogRules());
        $result = $client->updateAnalysis($site, $analysis, $validated, $request->user());

        // Un enregistrement automatique revient sur la fiche, sans message.
        if ($result['ok'] && $request->boolean('_autosave')) {
            return back();
        }

        return $this->respond($result, 'Analyse mise à jour sur le site.');
    }

    public function activate(Request $request, string $site, string $analysis, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->setAnalysisActive($site, $analysis, true, $request->user()),
            'Analyse activée sur le site.',
        );
    }

    public function deactivate(Request $request, string $site, string $analysis, PortalSiteApiClient $client): RedirectResponse
    {
        return $this->respond(
            $client->setAnalysisActive($site, $analysis, false, $request->user()),
            'Analyse désactivée sur le site.',
        );
    }

    public function import(
        Request $request,
        PortalSiteApiClient $client,
        ExcelWorkbook $excel,
    ): RedirectResponse {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);
        $rows = $excel->rows($validated['file']);

        return $this->respond(
            $client->importAnalyses($validated['site_code'], $rows, $request->user()),
            'Catalogue importé sur le site.',
        );
    }

    public function export(Request $request, PortalSiteApiClient $client, ExcelWorkbook $excel, AnalysisCatalogDirectory $directory): StreamedResponse
    {
        $validated = $request->validate([
            'site_code' => $this->siteCodeRules(),
            'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE', 'ALL'])],
        ]);
        $result = collect($client->analysisCatalogsForAllSites($request->user(), [
            'status' => $validated['status'] ?? 'ALL',
        ]))->firstWhere('site.code', $validated['site_code']);

        abort_unless($result && $result['ok'], 503, $result['message'] ?? 'Le site ne répond pas actuellement.');

        $rows = collect(data_get($result, 'data.analyses', []))->map(fn (array $item) => $directory->exportRow($item));

        return $excel->download(
            'catalogue-analyses-'.mb_strtolower($validated['site_code']).'-'.now()->format('Y-m-d-His'),
            'Catalogue analyses',
            AnalysisCatalogImportService::HEADERS,
            $rows,
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

    /** @return array<int, mixed> */
    private function siteCodeRules(): array
    {
        return ['required', Rule::in(collect(config('rivo.clinics', []))->pluck('code')->all())];
    }

    /** @return array{code: string, name: string} */
    private function siteMeta(string $siteCode): array
    {
        $site = collect(config('rivo.clinics', []))->firstWhere('code', $siteCode);
        abort_if(! $site, 404);

        return ['code' => $site['code'], 'name' => $site['name']];
    }

    /** @return array<string, mixed> */
    private function formData(string $siteCode, Request $request, PortalSiteApiClient $client): array
    {
        $result = collect($client->analysisCatalogsForAllSites($request->user()))
            ->firstWhere('site.code', $siteCode);

        abort_unless($result && $result['ok'], 503, $result['message'] ?? 'Le site ne répond pas actuellement.');

        return [
            'catalogItems' => data_get($result, 'data.catalog_items', []),
            'parents' => data_get($result, 'data.parents', []),
            'levels' => data_get($result, 'data.levels', ['PARENT', 'CHILD', 'NORMAL']),
            'resultTypes' => data_get($result, 'data.result_types', ['NUMERIC', 'TEXT', 'CHOICE', 'BOOLEAN']),
            'entryModes' => data_get($result, 'data.entry_modes', []),
            'disciplines' => data_get($result, 'data.disciplines', []),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogRules(): array
    {
        return [
            'catalog_item_uuid' => ['required', 'uuid'],
            'parent_uuid' => ['nullable', 'uuid'],
            'code' => ['required', 'string', 'max:80'],
            'level' => ['required', Rule::in(AnalysisCatalog::LEVELS)],
            'designation' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lab_discipline_uuid' => ['nullable', 'uuid'],
            'new_discipline_name' => ['nullable', 'string', 'max:120'],
            'result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'entry_mode' => ['nullable', Rule::in(LabEntryMode::values())],
            'reference_general' => ['nullable', 'string', 'max:255'],
            'reference_male' => ['nullable', 'string', 'max:255'],
            'reference_female' => ['nullable', 'string', 'max:255'],
            'reference_child_male' => ['nullable', 'string', 'max:255'],
            'reference_child_female' => ['nullable', 'string', 'max:255'],
            ...LabCriticalRange::rules(''),
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
            'children.*.result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'children.*.entry_mode' => ['nullable', Rule::in(LabEntryMode::values())],
            'children.*.reference_general' => ['nullable', 'string', 'max:255'],
            'children.*.reference_male' => ['nullable', 'string', 'max:255'],
            'children.*.reference_female' => ['nullable', 'string', 'max:255'],
            'children.*.reference_child_male' => ['nullable', 'string', 'max:255'],
            'children.*.reference_child_female' => ['nullable', 'string', 'max:255'],
            ...LabCriticalRange::rules('children.*.'),
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
            'children.*.children.*.result_type' => ['required', Rule::in(AnalysisCatalog::RESULT_TYPES)],
            'children.*.children.*.entry_mode' => ['nullable', Rule::in(LabEntryMode::values())],
            'children.*.children.*.reference_general' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_male' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_female' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_child_male' => ['nullable', 'string', 'max:255'],
            'children.*.children.*.reference_child_female' => ['nullable', 'string', 'max:255'],
            ...LabCriticalRange::rules('children.*.children.*.'),
            'children.*.children.*.unit' => ['nullable', 'string', 'max:60'],
            'children.*.children.*.predefined_values' => ['nullable', 'array', 'max:30'],
            'children.*.children.*.predefined_values.*' => ['required', 'string', 'max:100', 'distinct'],
            'children.*.children.*.display_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'children.*.children.*.is_active' => ['sometimes', 'boolean'],
            'children.*.children.*.is_bold' => ['sometimes', 'boolean'],
        ];
    }

    private function respond(array $result, string $successMessage): RedirectResponse
    {
        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [$field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages],
            )->all();

            return back()->withErrors($errors ?: ['site_code' => $result['message']]);
        }

        return to_route('super-admin.analyses.index')->with('status', $result['message'] ?: $successMessage);
    }
}
