<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SuperAdmin\Concerns\RespondsToSiteApi;
use App\Services\Pharmacy\MedicineSupplierImportService;
use App\Services\Spreadsheet\ExcelWorkbook;
use App\Services\SuperAdmin\PortalSiteApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-098 — the central portal's supplier folders. It never touches a clinic
 * database: every read and write goes through the selected site's API, which
 * re-checks the Super Admin's permissions and audits the remote actor.
 */
class PharmacySupplierController extends Controller
{
    use RespondsToSiteApi;

    /** Excel header (normalized) → field sent to the site. */
    private const IMPORT_COLUMNS = [
        'code' => 'code',
        'nom' => 'name',
        'personne_a_contacter' => 'contact_name',
        'telephone' => 'phone',
        'e_mail' => 'email',
        'adresse' => 'address',
    ];

    private const SESSION_KEY = 'pharmacy_supplier_imports';

    public function index(Request $request, PortalSiteApiClient $client): Response
    {
        $status = $request->query('status') === 'ARCHIVED' ? 'ARCHIVED' : 'ACTIVE';
        $sites = collect($client->pharmacySuppliersForAllSites($request->user(), ['status' => $status]))
            ->map(fn (array $result) => [
                'code' => $result['site']['code'],
                'name' => $result['site']['name'],
                'status' => $result['status'],
                'message' => $result['ok'] ? null : $result['message'],
                'suppliers' => $result['ok'] ? ($result['data'] ?? []) : [],
            ])
            ->values();

        $requested = mb_strtoupper((string) $request->query('site'));
        $user = $request->user();

        return Inertia::render('SuperAdmin/PharmacySuppliers/Index', [
            'sites' => $sites,
            'selectedSite' => $sites->contains('code', $requested)
                ? $requested
                : ($sites->firstWhere('status', 'ONLINE')['code'] ?? $sites->first()['code'] ?? null),
            'status' => $status,
            'can' => [
                'create' => $user->can('medicine_suppliers.create'),
                'import' => $user->can('medicine_suppliers.import'),
                'export' => $user->can('medicine_suppliers.export'),
            ],
        ]);
    }

    public function store(Request $request, PortalSiteApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in($this->siteCodes())],
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond(
            $client->createPharmacySupplier($validated['site_code'], collect($validated)->except('site_code')->all(), $request->user()),
            'Fournisseur créé.',
        );
    }

    public function export(Request $request, PortalSiteApiClient $client, ExcelWorkbook $excel): StreamedResponse
    {
        $validated = $request->validate(['site_code' => ['required', Rule::in(['ALL', ...$this->siteCodes()])]]);
        $results = $validated['site_code'] === 'ALL'
            ? collect($client->pharmacySuppliersForAllSites($request->user(), ['status' => 'ALL']))
            : collect([$client->pharmacySuppliers($validated['site_code'], $request->user(), ['status' => 'ALL'])]);
        $sites = $results->where('ok', true)->values();

        abort_if($sites->isEmpty(), 503, 'Aucun site sélectionné ne répond actuellement : aucun export n’a été généré.');

        $rows = $sites->flatMap(fn (array $site) => collect($site['data'] ?? [])->map(fn (array $supplier) => [
            $site['site']['code'],
            $site['site']['name'],
            $supplier['code'],
            $supplier['name'],
            $supplier['contact_name'] ?? '',
            $supplier['phone'] ?? '',
            $supplier['email'] ?? '',
            $supplier['address'] ?? '',
            $supplier['catalogs_count'] ?? 0,
            ($supplier['archived'] ?? false) ? 'Archivé' : 'Actif',
            $supplier['delete_reason'] ?? '',
        ]));
        $scope = $validated['site_code'] === 'ALL' ? 'tous-les-sites' : mb_strtolower($validated['site_code']);

        return $excel->download(
            'fournisseurs-'.$scope.'-'.now()->format('Y-m-d-His'),
            'Fournisseurs',
            ['Code site', 'Site', 'Code', 'Nom', 'Personne à contacter', 'Téléphone', 'E-mail', 'Adresse', 'Catalogues', 'Statut', 'Motif d’archivage'],
            $rows,
        );
    }

    public function template(ExcelWorkbook $excel): StreamedResponse
    {
        return $excel->download(
            'modele-import-fournisseurs',
            'Fournisseurs à importer',
            ['Code', 'Nom', 'Personne à contacter', 'Téléphone', 'E-mail', 'Adresse'],
            [
                ['PHARMADIS', 'Pharmadis Madagascar', 'Mme Rasoa', '034 12 345 67', 'commandes@exemple.mg', 'Antananarivo'],
                ['SOMAPHAR', 'Somaphar', '', '', '', ''],
            ],
        );
    }

    /** First pass: read the file, let the site say what each line would do. */
    public function analyzeImport(Request $request, PortalSiteApiClient $client, ExcelWorkbook $excel): RedirectResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', Rule::in($this->siteCodes())],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);
        [$rows, $skipped] = $this->importRows($excel->rows($validated['file']));
        $result = $client->previewPharmacySupplierImport($validated['site_code'], $rows, $request->user());

        if (! $result['ok']) {
            return $this->respond($result, '');
        }

        $token = (string) Str::uuid();
        $request->session()->put(self::SESSION_KEY.'.'.$token, [
            'site_code' => $validated['site_code'],
            'site' => $result['site'],
            'file_name' => $validated['file']->getClientOriginalName(),
            'rows' => $rows,
            'plan' => $result['data'],
            'skipped_archived' => $skipped,
        ]);

        return to_route('super-admin.pharmacy-suppliers.import.show', $token);
    }

    public function showImport(Request $request, string $token): Response|RedirectResponse
    {
        $pending = $request->session()->get(self::SESSION_KEY.'.'.$token);

        if (! is_array($pending)) {
            return $this->expired();
        }

        return Inertia::render('SuperAdmin/PharmacySuppliers/ImportSuppliers', [
            'token' => $token,
            'targetSite' => $pending['site'],
            'fileName' => $pending['file_name'],
            'rows' => $pending['plan']['rows'] ?? [],
            'summary' => $pending['plan']['summary'] ?? [],
            'skippedArchived' => $pending['skipped_archived'],
        ]);
    }

    /** Second pass: the site re-checks every line under lock, then writes. */
    public function confirmImport(Request $request, string $token, PortalSiteApiClient $client): RedirectResponse
    {
        $key = self::SESSION_KEY.'.'.$token;
        $pending = $request->session()->get($key);

        if (! is_array($pending)) {
            return $this->expired();
        }

        $result = $client->importPharmacySuppliers($pending['site_code'], $pending['rows'], $request->user());

        if (! $result['ok']) {
            return $this->respond($result, '');
        }

        $request->session()->forget($key);

        return to_route('super-admin.pharmacy-suppliers.index', ['site' => $pending['site_code']])
            ->with('status', $result['message'] ?: 'Fournisseurs importés.');
    }

    /** The folder, laid out like the clinic's: identity, then its sub-folders. */
    public function show(Request $request, string $site, string $supplier, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacySupplierFolder($site, $supplier, $request->user());
        $user = $request->user();

        return Inertia::render('SuperAdmin/PharmacySuppliers/Show', [
            'targetSite' => $result['site'],
            'supplier' => $result['ok'] ? data_get($result, 'data.supplier') : null,
            'counts' => $result['ok'] ? data_get($result, 'data.counts', []) : [],
            'error' => $result['ok'] ? null : $result['message'],
            'can' => [
                'view_catalogs' => $user->can('view-supplier-catalogs'),
                'view_orders' => $user->can('view-supplier-orders'),
                'view_invoices' => $user->can('view-supplier-invoices'),
                'view_offers' => $user->can('view-supplier-offers'),
                'update_supplier' => $user->can('medicine_suppliers.update'),
                'archive_supplier' => $user->can('medicine_suppliers.delete'),
                'restore_supplier' => $user->can('medicine_suppliers.restore'),
            ],
        ]);
    }

    public function catalogs(Request $request, string $site, string $supplier, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacySupplierCatalogs($site, $supplier, $request->user());
        $user = $request->user();

        return Inertia::render('SuperAdmin/PharmacySuppliers/Catalogs', [
            'targetSite' => $result['site'],
            'supplier' => $result['ok'] ? data_get($result, 'data.supplier') : null,
            'catalogs' => $result['ok'] ? data_get($result, 'data.catalogs', []) : [],
            'error' => $result['ok'] ? null : $result['message'],
            'can' => [
                'create' => $user->can('supplier_catalogs.create'),
                'update' => $user->can('supplier_catalogs.update'),
                'delete' => $user->can('supplier_catalogs.delete'),
                'restore' => $user->can('supplier_catalogs.restore'),
            ],
        ]);
    }

    public function orders(Request $request, string $site, string $supplier, PortalSiteApiClient $client): Response
    {
        return $this->folderSection($request, $site, $supplier, $client, 'orders', 'SuperAdmin/PharmacySuppliers/Orders');
    }

    public function invoices(Request $request, string $site, string $supplier, PortalSiteApiClient $client): Response
    {
        return $this->folderSection($request, $site, $supplier, $client, 'invoices', 'SuperAdmin/PharmacySuppliers/Invoices');
    }

    public function products(Request $request, string $site, string $supplier, PortalSiteApiClient $client): Response
    {
        return $this->folderSection($request, $site, $supplier, $client, 'products', 'SuperAdmin/PharmacySuppliers/Products');
    }

    public function update(Request $request, string $site, string $supplier, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond($client->updatePharmacySupplier($site, $supplier, $validated, $request->user()), 'Fournisseur mis à jour.');
    }

    public function archive(Request $request, string $site, string $supplier, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond($client->archivePharmacySupplier($site, $supplier, $validated['reason'], $request->user()), 'Fournisseur archivé.');
    }

    public function restore(Request $request, string $site, string $supplier, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond($client->restorePharmacySupplier($site, $supplier, $request->user()), 'Fournisseur restauré.');
    }

    public function uploadCatalog(Request $request, string $site, string $supplier, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,pdf', 'max:10240'],
            'catalog_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond(
            $client->uploadPharmacySupplierCatalog(
                $site,
                $supplier,
                $validated['file'],
                collect($validated)->except('file')->filter(fn ($value) => filled($value))->all(),
                $request->user(),
            ),
            'Catalogue ajouté au dossier du fournisseur.',
        );
    }

    public function activateCatalog(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond($client->activatePharmacySupplierCatalog($site, $supplier, $catalog, $request->user()), 'Catalogue activé.');
    }

    public function catalogItems(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacySupplierCatalogItems($site, $supplier, $catalog, $request->user());

        return Inertia::render('SuperAdmin/PharmacySuppliers/CatalogItems', [
            'targetSite' => $result['site'],
            'supplier' => data_get($result, 'data.supplier'),
            'catalog' => data_get($result, 'data.catalog'),
            'items' => data_get($result, 'data.items', []),
            'error' => $result['ok'] ? null : $result['message'],
        ]);
    }

    public function updateCatalog(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['catalog_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);

        return $this->respond(
            $client->updatePharmacySupplierCatalog($site, $supplier, $catalog, $validated, $request->user()),
            'Catalogue mis à jour.',
        );
    }

    public function archiveCatalog(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->respond(
            $client->archivePharmacySupplierCatalog($site, $supplier, $catalog, $validated['reason'], $request->user()),
            'Catalogue archivé.',
        );
    }

    public function restoreCatalog(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);

        return $this->respond($client->restorePharmacySupplierCatalog($site, $supplier, $catalog, $request->user()), 'Catalogue restauré.');
    }

    public function previewImport(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): Response|RedirectResponse
    {
        $this->assertSite($site);
        $result = $client->previewPharmacySupplierCatalogImport($site, $supplier, $catalog, $request->user());

        if (! $result['ok']) {
            return $this->respond($result, '');
        }

        return Inertia::render('SuperAdmin/PharmacySuppliers/ImportPreview', [
            'targetSite' => $result['site'],
            'supplier' => data_get($result, 'data.supplier'),
            'catalog' => data_get($result, 'data.catalog'),
            'preview' => data_get($result, 'data.preview'),
        ]);
    }

    public function import(Request $request, string $site, string $supplier, string $catalog, PortalSiteApiClient $client): RedirectResponse
    {
        $this->assertSite($site);
        $result = $client->importPharmacySupplierCatalog($site, $supplier, $catalog, $request->user());

        if (! $result['ok']) {
            return $this->respond($result, '');
        }

        return to_route('super-admin.pharmacy-suppliers.catalogs.index', ['site' => mb_strtoupper($site), 'supplier' => $supplier])
            ->with('status', $result['message'] ?: 'Catalogue importé.');
    }

    /** A read-only sub-folder: whatever the site returns, under its own page. */
    private function folderSection(Request $request, string $site, string $supplier, PortalSiteApiClient $client, string $section, string $component): Response
    {
        $this->assertSite($site);
        $result = $client->pharmacySupplierFolder($site, $supplier, $request->user(), $section);
        $data = $result['ok'] ? ($result['data'] ?? []) : [];

        $user = $request->user();
        $active = ! ($data['supplier']['archived'] ?? false);

        return Inertia::render($component, [
            'targetSite' => $result['site'],
            'supplier' => $data['supplier'] ?? null,
            ...collect($data)->except('supplier')->all(),
            'error' => $result['ok'] ? null : $result['message'],
            // New orders and invoices go to active suppliers only (ADR-098).
            'can' => [
                'create_order' => $active && $user->can('purchase_orders.create'),
                'create_invoice' => $active && $user->can('supplier_invoices.create'),
            ],
        ]);
    }

    /**
     * Maps the spreadsheet onto the fields the site expects. Only the file's
     * shape is checked here; every business rule is decided by the site.
     *
     * @param  array<int, array<string, mixed>>  $rawRows
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function importRows(array $rawRows): array
    {
        if ($rawRows === []) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucun fournisseur à importer.']);
        }

        $missing = array_diff(['code', 'nom'], array_keys($rawRows[0]));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'Colonnes obligatoires absentes : '.collect($missing)->map(fn ($column) => $column === 'nom' ? 'Nom' : 'Code')->implode(', ')
                    .'. Téléchargez le modèle Excel pour voir la présentation attendue.',
            ]);
        }

        $rows = [];
        $skipped = 0;

        foreach ($rawRows as $index => $row) {
            // A file exported from here lists archived suppliers too: those
            // lines describe history, not something to create again.
            if (Str::of((string) ($row['statut'] ?? ''))->ascii()->lower()->trim()->toString() === 'archive') {
                $skipped++;

                continue;
            }

            $mapped = ['line' => $index + 2];

            foreach (self::IMPORT_COLUMNS as $header => $field) {
                $value = $row[$header] ?? null;
                $mapped[$field] = is_scalar($value) ? $value : null;
            }

            $rows[] = $mapped;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Toutes les lignes du fichier concernent des fournisseurs archivés : rien à importer.']);
        }

        if (count($rows) > MedicineSupplierImportService::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'Un import est limité à '.MedicineSupplierImportService::MAX_ROWS.' fournisseurs.']);
        }

        return [$rows, $skipped];
    }

    private function expired(): RedirectResponse
    {
        return to_route('super-admin.pharmacy-suppliers.index')->withErrors([
            'file' => 'Cet aperçu n’est plus disponible (déjà importé ou expiré). Importez à nouveau le fichier.',
        ]);
    }
}
