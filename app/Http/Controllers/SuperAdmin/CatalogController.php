<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\CatalogTariffCategory;
use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogDirectory;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
use App\Support\Catalog\CatalogTariffReason;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogController extends Controller
{
    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $selectedSite = mb_strtoupper(trim((string) $request->query('site', '')));

        if ($selectedSite !== '' && ! in_array($selectedSite, $this->siteCodes(), true)) {
            abort(404);
        }

        // ADR-044, amendement du 2026-09-28 (ter) — le même écran que le site.
        return Inertia::render('Catalog/Index', [
            'context' => ['mode' => 'portal'],
            'sites' => $client->catalogForAllSites($request->user(), ['status' => 'ALL']),
            'selectedSiteCode' => $selectedSite ?: null,
            // Les médicaments hors référentiel se traitent au site (ADR-037) :
            // une ligne d'ordonnance n'a pas d'identifiant à exposer par l'API.
            'pendingMedicines' => [],
        ]);
    }

    public function export(
        Request $request,
        PortalSiteApiClient $client,
        ExcelWorkbook $excel,
    ): StreamedResponse {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in(['ALL', ...$this->siteCodes()])],
            'uuids' => ['nullable', 'array', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            // Une catégorie de l'écran : un domaine, ou une famille d'imagerie (`IMAGING:ULTRASOUND`).
            'module' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z_]+(:[A-Z_]+)?$/'],
        ]);
        $category = $validated['module'] ?? null;
        $sites = collect($client->catalogForAllSites($request->user(), ['status' => 'ALL']))
            ->when($validated['site_code'] !== 'ALL', fn ($items) => $items->where('site.code', $validated['site_code']))
            ->where('ok', true)
            ->values();

        abort_if($sites->isEmpty(), 503, 'Aucun site sélectionné ne fournit actuellement ses tarifs.');

        $selectedUuids = collect($validated['uuids'] ?? []);
        $selectedItems = $sites->flatMap(fn (array $site) => collect(data_get($site, 'data.items', []))
            ->when($selectedUuids->isNotEmpty(), fn ($items) => $items->whereIn('uuid', $selectedUuids))
            ->when($category !== null, fn ($items) => $items->filter(fn (array $item) => $this->inCategory($item, $category)))
            ->map(fn (array $item) => ['site' => $site, 'item' => $item]));

        if ($selectedUuids->isNotEmpty() && $selectedItems->count() !== $selectedUuids->count()) {
            throw ValidationException::withMessages([
                'uuids' => 'Une désignation sélectionnée est absente des données actuelles. Aucun export n’a été généré.',
            ]);
        }

        $rows = $selectedItems->map(fn (array $selected) => [
            data_get($selected, 'site.site.code'),
            data_get($selected, 'site.site.name'),
            data_get($selected, 'item.code'),
            data_get($selected, 'item.name'),
            data_get($selected, 'item.module_label'),
            data_get($selected, 'item.unit'),
            data_get($selected, 'item.current_standard_tariff.amount'),
            data_get($selected, 'item.current_mutual_tariff.amount'),
            data_get($selected, 'item.archived') ? 'ARCHIVED' : 'ACTIVE',
        ]);
        $scope = $selectedUuids->isNotEmpty()
            ? 'selection-'.$selectedUuids->count()
            : ($validated['site_code'] === 'ALL' ? 'tous-les-sites' : mb_strtolower($validated['site_code']));
        if ($category !== null && $selectedUuids->isEmpty()) {
            $scope .= '-'.mb_strtolower(str_replace([':', '_'], '-', $category));
        }

        return $excel->download(
            'tarifs-'.$scope.'-'.now()->format('Y-m-d-His'),
            'Tarifs Standard et Mutuelle',
            [
                'Code site', 'Site', 'Code désignation', 'Désignation', 'Module', 'Unité',
                'Tarif sans mutuelle', 'Tarif mutuelle', 'Statut',
            ],
            $rows,
        );
    }

    /**
     * La catégorie d'une désignation, comme l'écran la lit : son domaine, et pour
     * l'Imagerie sa famille réglée au catalogue (ADR-106). Une famille absente
     * reste « non classée », jamais devinée.
     *
     * @param  array<string, mixed>  $item
     */
    private function inCategory(array $item, string $category): bool
    {
        [$module, $family] = array_pad(explode(':', $category, 2), 2, null);

        if (($item['module'] ?? null) !== $module) {
            return false;
        }

        if ($family === null) {
            return true;
        }

        return $family === 'UNCLASSIFIED'
            ? blank($item['imaging_modality'] ?? null)
            : ($item['imaging_modality'] ?? null) === $family;
    }

    public function template(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'modele-import-tarifs',
            'Tarifs à importer',
            ['Code désignation', 'Tarif sans mutuelle', 'Tarif mutuelle', 'Motif de modification'],
            [
                ['CONSULT-GEN', 20000, 18000, 'Mise à jour de la grille tarifaire validée'],
                ['ECHO-ABD', 50000, 45000, 'Mise à jour de la grille tarifaire validée'],
            ],
        );
    }

    public function import(
        Request $request,
        PortalSiteApiClient $client,
        ExcelWorkbook $excel,
    ): RedirectResponse {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in($this->siteCodes())],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);
        $rows = $this->tariffImportRows($excel->rows($validated['file']));

        return $this->respond(
            $client->importCatalogTariffs($validated['site_code'], $rows, $request->user()),
            count($rows).' ligne(s) tarifaire(s) traitée(s).',
        );
    }

    /** La page « Nouvelle désignation » d'un site, rangée d'avance dans la catégorie d'où l'on vient. */
    public function create(Request $request, PortalSiteApiClient $client): Response
    {
        $siteCode = $this->queriedSiteCode($request);
        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:40', 'regex:/^[A-Z_]+(:[A-Z_]+)?$/'],
        ]);
        $result = $client->catalogOptions($siteCode, $request->user());

        return Inertia::render('Catalog/ItemForm', [
            'context' => ['mode' => 'portal'],
            // Jamais `site` : la prop partagée du même nom porte le menu du portail.
            'targetSite' => $this->siteIdentity($siteCode),
            'item' => null,
            'options' => $result['ok'] ? data_get($result, 'data.options') : null,
            'consumableOptions' => [],
            'category' => $validated['module'] ?? null,
            'siteError' => $result['ok'] ? null : $result['message'],
        ]);
    }

    /** La fiche d'une désignation : ce qui la décrit, puis ses deux tarifs et leur historique. */
    public function edit(Request $request, string $site, string $catalog, PortalSiteApiClient $client): Response
    {
        $this->assertRouteSite($site);
        $siteCode = mb_strtoupper($site);
        $result = $client->catalogItem($siteCode, $catalog, $request->user());
        abort_if(($result['http_status'] ?? null) === 404, 404);

        return Inertia::render('Catalog/ItemForm', [
            'context' => ['mode' => 'portal'],
            // Jamais `site` : la prop partagée du même nom porte le menu du portail.
            'targetSite' => $this->siteIdentity($siteCode),
            'item' => $result['ok'] ? data_get($result, 'data.item') : null,
            'options' => $result['ok'] ? data_get($result, 'data.options') : null,
            'consumableOptions' => $result['ok'] ? (data_get($result, 'data.consumable_options') ?? []) : [],
            'category' => null,
            'siteError' => $result['ok'] ? null : $result['message'],
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $siteCode = $this->validatedSiteCode($request);
        $request->validate(['tariff_reason_auto' => ['sometimes', 'boolean']]);
        $payload = $this->itemPayload($request, true);

        // Le motif automatique est écrit ici, jamais par le navigateur : il dit ce qui s'est passé.
        if ($request->boolean('tariff_reason_auto') && filled($payload['tariff_amount'] ?? null)) {
            $payload['tariff_reason'] = CatalogTariffReason::INITIAL;
        }

        $result = $client->createCatalogItem($siteCode, $payload, $request->user());

        if (! $result['ok']) {
            return $this->respond($result, '');
        }

        // Retour à la catégorie de la nouvelle désignation, ouverte sur son code.
        return redirect('/super-admin/workspaces/tariffs?'.http_build_query(array_filter([
            'site' => $siteCode,
            'module' => CatalogDirectory::categoryOf((array) ($result['data'] ?? [])),
            'q' => data_get($result, 'data.code'),
        ])))->with('status', $result['message'] ?: 'Désignation créée.');
    }

    public function update(
        Request $request,
        string $site,
        string $catalog,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertRouteSite($site);

        return $this->respond(
            $client->updateCatalogItem($site, $catalog, $this->itemPayload($request), $request->user()),
            'Désignation mise à jour.',
        );
    }

    public function destroy(
        Request $request,
        string $site,
        string $catalog,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertRouteSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->respond(
            $client->archiveCatalogItem($site, $catalog, $validated['reason'], $request->user()),
            'Désignation archivée.',
        );
    }

    public function restore(
        Request $request,
        string $site,
        string $catalog,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertRouteSite($site);

        return $this->respond(
            $client->restoreCatalogItem($site, $catalog, $request->user()),
            'Désignation restaurée.',
        );
    }

    public function bulkArchive(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $siteCode = $this->validatedSiteCode($request);
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return $this->respond(
            $client->bulkArchiveCatalogItems(
                $siteCode,
                $validated['uuids'],
                $validated['reason'],
                $request->user(),
            ),
            count($validated['uuids']).' désignation(s) archivée(s).',
        );
    }

    public function bulkRestore(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $siteCode = $this->validatedSiteCode($request);
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:100'],
            'uuids.*' => ['required', 'uuid', 'distinct'],
        ]);

        return $this->respond(
            $client->bulkRestoreCatalogItems($siteCode, $validated['uuids'], $request->user()),
            count($validated['uuids']).' désignation(s) restaurée(s).',
        );
    }

    public function setTariff(
        Request $request,
        string $site,
        string $catalog,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertRouteSite($site);
        abort_unless(
            $request->user()->can('catalog.tariffs.create')
                || $request->user()->can('catalog.tariffs.update'),
            403,
        );
        $validated = $request->validate([
            'tariff_category' => ['required', new Enum(CatalogTariffCategory::class)],
            'tariff_amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'reason_auto' => ['sometimes', 'boolean'],
            'reason' => [Rule::requiredIf(! $request->boolean('reason_auto')), 'nullable', 'string', 'max:1000'],
        ]);

        // Le motif automatique dit la grille et le montant : l'historique reste lisible sans rien taper.
        if ($request->boolean('reason_auto')) {
            $validated['reason'] = CatalogTariffReason::change(
                CatalogTariffCategory::from($validated['tariff_category']),
                (string) $validated['tariff_amount'],
            );
        }

        return $this->respond(
            $client->setCatalogTariff(
                $site,
                $catalog,
                $validated['tariff_category'],
                $validated['tariff_amount'],
                $validated['reason'],
                $request->user(),
            ),
            'Tarif enregistré.',
        );
    }

    public function archiveTariff(
        Request $request,
        string $site,
        string $catalog,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertRouteSite($site);
        $validated = $request->validate([
            'tariff_category' => ['required', new Enum(CatalogTariffCategory::class)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->respond(
            $client->archiveCatalogTariff(
                $site,
                $catalog,
                $validated['tariff_category'],
                $validated['reason'],
                $request->user(),
            ),
            'Tarif suspendu.',
        );
    }

    /**
     * ADR-072 / ADR-142 / ADR-169 — le matériel habituel d'un acte, réglé depuis
     * sa fiche. Le site revérifie le droit et que l'acte peut en recevoir.
     */
    public function syncCareConsumables(
        Request $request,
        string $site,
        string $catalog,
        PortalSiteApiClient $client,
    ): RedirectResponse {
        $this->assertRouteSite($site);
        $validated = $request->validate([
            'consumables' => ['present', 'array', 'max:20'],
            'consumables.*.medicine_uuid' => ['required', 'uuid', 'distinct'],
            'consumables.*.default_quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->respond(
            $client->syncCatalogCareConsumables($site, $catalog, $validated['consumables'], $request->user()),
            'Matériel habituel mis à jour.',
        );
    }

    /** Le site de la page, lu sur `?site=` ; à défaut le premier site configuré. */
    private function queriedSiteCode(Request $request): string
    {
        $code = mb_strtoupper(trim((string) $request->query('site', '')));
        $codes = $this->siteCodes();
        abort_if($codes === [], 404);

        if ($code === '') {
            return $codes[0];
        }

        abort_unless(in_array($code, $codes, true), 404);

        return $code;
    }

    /** @return array{code: string, name: string} */
    private function siteIdentity(string $code): array
    {
        $site = collect(config('rivo.clinics', []))->firstWhere('code', $code) ?? [];

        return ['code' => $code, 'name' => (string) ($site['name'] ?? $code)];
    }

    private function validatedSiteCode(Request $request): string
    {
        return $request->validate([
            'site_code' => ['required', Rule::in($this->siteCodes())],
        ])['site_code'];
    }

    private function assertRouteSite(string $site): void
    {
        abort_unless(in_array(mb_strtoupper($site), $this->siteCodes(), true), 404);
    }

    /** @return array<int, string> */
    private function siteCodes(): array
    {
        return collect(config('rivo.clinics', []))->pluck('code')->all();
    }

    /** @return array<string, mixed> */
    private function itemPayload(Request $request, bool $creating = false): array
    {
        $fields = [
            'name', 'module', 'imaging_modality', 'unit', 'reception_selectable',
            'reception_routing_mode', 'staff_coverage_policy', 'care_requires_allergy_check',
            'care_recommends_vitals', 'clinician_orderable', 'description',
        ];

        if ($creating) {
            $fields = [
                'code', 'type', 'billable', 'stockable', 'tariff_amount',
                'mutual_tariff_amount', 'tariff_reason', ...$fields,
            ];
        }

        return $request->only($fields);
    }

    private function respond(array $result, string $successMessage): RedirectResponse
    {
        if (! $result['ok']) {
            $errors = collect($result['errors'] ?? [])->mapWithKeys(
                fn ($messages, $field) => [
                    $field => is_array($messages) ? ($messages[0] ?? $result['message']) : $messages,
                ],
            )->all();

            return back()->withErrors($errors ?: ['site_code' => $result['message']]);
        }

        return back()->with('status', $result['message'] ?: $successMessage);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawRows
     * @return array<int, array{code: string, standard_amount?: string, mutual_amount?: string, reason: string}>
     */
    private function tariffImportRows(array $rawRows): array
    {
        $rows = collect();

        foreach ($rawRows as $index => $row) {
            $code = mb_strtoupper(trim((string) ($row['code_designation'] ?? $row['code'] ?? '')));

            if ($code === '') {
                throw ValidationException::withMessages([
                    'file' => sprintf('Le code de désignation manque à la ligne %d.', $index + 2),
                ]);
            }

            $standard = $this->importAmount($row['tarif_sans_mutuelle'] ?? $row['standard_amount'] ?? null, $index);
            $mutual = $this->importAmount($row['tarif_mutuelle'] ?? $row['mutual_amount'] ?? null, $index);

            if ($standard === null && $mutual === null) {
                throw ValidationException::withMessages([
                    'file' => sprintf('Renseignez au moins un tarif à la ligne %d.', $index + 2),
                ]);
            }

            $reason = str((string) ($row['motif_de_modification'] ?? $row['motif'] ?? ''))->squish()->toString();
            $reason = $reason !== '' ? $reason : 'Import Excel de la grille tarifaire';

            $rows->push(array_filter([
                'code' => $code,
                'standard_amount' => $standard,
                'mutual_amount' => $mutual,
                'reason' => $reason,
            ], fn ($value) => $value !== null));

            if ($rows->count() > 1000) {
                throw ValidationException::withMessages(['file' => 'Un import est limité à 1 000 désignations.']);
            }
        }

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucun tarif à importer.']);
        }

        if ($rows->pluck('code')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['file' => 'Le fichier contient plusieurs lignes pour la même désignation.']);
        }

        return $rows->values()->all();
    }

    private function importAmount(mixed $value, int $index): ?string
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) ($value ?? '')));

        if ($value === '') {
            return null;
        }

        try {
            $normalized = Money::normalize($value);
        } catch (\InvalidArgumentException|\OverflowException) {
            throw ValidationException::withMessages([
                'file' => sprintf('Le montant de la ligne %d est invalide.', $index + 2),
            ]);
        }

        if (Money::toMinor($normalized) <= 0 || Money::toMinor($normalized) > 99_999_999_999) {
            throw ValidationException::withMessages([
                'file' => sprintf('Le montant de la ligne %d doit être supérieur à zéro.', $index + 2),
            ]);
        }

        return $normalized;
    }
}
