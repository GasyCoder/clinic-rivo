<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Enums\CatalogTariffCategory;
use App\Http\Controllers\Controller;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
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

        return Inertia::render('SuperAdmin/Tariffs/Index', [
            'sites' => $client->catalogForAllSites($request->user(), ['status' => 'ALL']),
            'selectedSiteCode' => $selectedSite ?: null,
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
        ]);
        $sites = collect($client->catalogForAllSites($request->user(), ['status' => 'ALL']))
            ->when($validated['site_code'] !== 'ALL', fn ($items) => $items->where('site.code', $validated['site_code']))
            ->where('ok', true)
            ->values();

        abort_if($sites->isEmpty(), 503, 'Aucun site sélectionné ne fournit actuellement ses tarifs.');

        $selectedUuids = collect($validated['uuids'] ?? []);
        $selectedItems = $sites->flatMap(fn (array $site) => collect(data_get($site, 'data.items', []))
            ->when($selectedUuids->isNotEmpty(), fn ($items) => $items->whereIn('uuid', $selectedUuids))
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

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $siteCode = $this->validatedSiteCode($request);

        return $this->respond(
            $client->createCatalogItem($siteCode, $this->itemPayload($request, true), $request->user()),
            'Désignation créée.',
        );
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
            'reason' => ['required', 'string', 'max:1000'],
        ]);

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
            'name', 'module', 'unit', 'reception_selectable',
            'reception_routing_mode', 'staff_coverage_policy', 'care_requires_allergy_check',
            'care_recommends_vitals', 'description',
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
