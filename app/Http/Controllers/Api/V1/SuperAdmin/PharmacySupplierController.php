<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Actions\Pharmacy\ActivateSupplierCatalogAction;
use App\Actions\Pharmacy\ArchiveMedicineSupplierAction;
use App\Actions\Pharmacy\ArchiveSupplierCatalogAction;
use App\Actions\Pharmacy\ArchiveSupplierCatalogItemAction;
use App\Actions\Pharmacy\CreateMedicineSupplierAction;
use App\Actions\Pharmacy\RestoreMedicineSupplierAction;
use App\Actions\Pharmacy\RestoreSupplierCatalogAction;
use App\Actions\Pharmacy\RestoreSupplierCatalogItemAction;
use App\Actions\Pharmacy\UnlinkSupplierCatalogItemAction;
use App\Actions\Pharmacy\UpdateMedicineSupplierAction;
use App\Actions\Pharmacy\UpdateSupplierCatalogAction;
use App\Actions\Pharmacy\UpdateSupplierCatalogItemAction;
use App\Actions\Pharmacy\UploadSupplierCatalogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\UpdateSupplierCatalogItemRequest;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\PurchaseOrder;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierInvoice;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\MedicineSupplierImportService;
use App\Services\Pharmacy\SupplierCatalogImportService;
use App\Services\Pharmacy\SupplierOfferComparison;
use App\Services\Pharmacy\SupplierPresenter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-098 — suppliers and their catalogs are managed from the central portal
 * through this site API (ADR-004/025). Nothing is reimplemented here: every
 * write goes through the same Actions the clinic uses, with the remote Super
 * Admin as actor. Orders, receptions and invoices stay on the site.
 */
class PharmacySupplierController extends Controller
{
    public function __construct(private readonly SupplierPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeActor($request, 'medicine_suppliers.view');
        $status = $request->validate(['status' => ['nullable', Rule::in(['ACTIVE', 'ARCHIVED', 'ALL'])]])['status'] ?? 'ACTIVE';

        return response()->json([
            'data' => MedicineSupplier::query()
                ->when($status === 'ARCHIVED', fn ($query) => $query->onlyTrashed())
                ->when($status === 'ALL', fn ($query) => $query->withTrashed())
                ->withCount('catalogs')
                ->orderBy('name')
                ->get()
                ->map(fn (MedicineSupplier $supplier) => [
                    ...$this->presenter->identity($supplier),
                    'catalogs_count' => $supplier->catalogs_count,
                ])
                ->values(),
            'meta' => ['site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')]],
        ]);
    }

    /**
     * Every supplier's current price for the same medicine, with what the
     * clinic still holds — the reading an order is prepared from.
     */
    public function offers(Request $request, SupplierOfferComparison $comparison): JsonResponse
    {
        $this->authorizeActor($request, ['medicine_supplier_offers.view', 'medicine_suppliers.view']);
        $suppliers = array_values(array_filter((array) $request->query('suppliers', [])));

        return response()->json([
            'data' => $comparison->forSite($suppliers),
            'meta' => ['site' => ['code' => config('rivo.site.code'), 'name' => config('rivo.site.name')]],
        ]);
    }

    /**
     * The catalogue file itself. ADR-098 kept it on the site only, so the
     * portal could see a catalogue listed but never open it — the file is
     * exactly what a Super Admin needs to check before importing it.
     * The bytes are streamed through the site API, never copied centrally.
     */
    public function downloadCatalog(Request $request, string $supplierUuid, string $catalogUuid): StreamedResponse
    {
        $this->authorizeActor($request, ['supplier_catalogs.view', 'medicine_suppliers.view']);
        $supplier = $this->supplier($supplierUuid, withArchived: true);
        $catalog = $supplier->catalogs()->withTrashed()->where('uuid', $catalogUuid)->firstOrFail();

        abort_unless(Storage::disk('local')->exists($catalog->path), 404);

        return Storage::disk('local')->download($catalog->path, $catalog->original_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, CreateMedicineSupplierAction $action): JsonResponse
    {
        $this->authorizeActor($request, 'medicine_suppliers.create');
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/', Rule::unique('medicine_suppliers', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);

        $supplier = $action->execute($validated, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => "Dossier du fournisseur {$supplier->name} créé.",
            'data' => $this->presenter->identity($supplier),
        ], 201);
    }

    /** The folder itself: identity and what each sub-folder holds. */
    public function show(Request $request, string $supplierUuid): JsonResponse
    {
        $this->authorizeActor($request, 'medicine_suppliers.view');
        $supplier = $this->supplier($supplierUuid, withArchived: true);

        return response()->json([
            'data' => [
                'supplier' => $this->presenter->identity($supplier),
                'counts' => $this->presenter->folderCounts($supplier),
            ],
        ]);
    }

    /** Orders are placed and received at the site; the portal only reads them. */
    public function orders(Request $request, string $supplierUuid): JsonResponse
    {
        $this->authorizeActor($request, ['purchase_orders.view', 'medicine_suppliers.view']);
        $supplier = $this->supplier($supplierUuid, withArchived: true);

        return response()->json([
            'data' => [
                'supplier' => $this->presenter->identity($supplier),
                'orders' => $supplier->purchaseOrders()
                    ->with('supplier:id,uuid,name')
                    ->latest('created_at')
                    ->limit(200)
                    ->get()
                    ->map(fn (PurchaseOrder $order) => $this->presenter->order($order))
                    ->values(),
            ],
        ]);
    }

    public function invoices(Request $request, string $supplierUuid): JsonResponse
    {
        $this->authorizeActor($request, ['supplier_invoices.view', 'medicine_suppliers.view']);
        $supplier = $this->supplier($supplierUuid, withArchived: true);

        return response()->json([
            'data' => [
                'supplier' => $this->presenter->identity($supplier),
                'invoices' => SupplierInvoice::query()->withTrashed()
                    ->where('medicine_supplier_id', $supplier->id)
                    ->with('supplier:id,uuid,name')
                    ->latest('invoice_date')
                    ->limit(200)
                    ->get()
                    ->map(fn (SupplierInvoice $invoice) => $this->presenter->invoice($invoice))
                    ->values(),
            ],
        ]);
    }

    public function products(Request $request, string $supplierUuid): JsonResponse
    {
        $this->authorizeActor($request, ['medicine_supplier_offers.view', 'medicine_suppliers.view']);
        $supplier = $this->supplier($supplierUuid, withArchived: true);

        [$current, $past] = $supplier->offers()
            ->with('medicine.catalogItem:id,code,name')
            ->orderByDesc('effective_from')
            ->get()
            ->partition(fn (MedicineSupplierOffer $offer) => $offer->isCurrent());

        return response()->json([
            'data' => [
                'supplier' => $this->presenter->identity($supplier),
                'offers' => $current->map(fn (MedicineSupplierOffer $offer) => $this->presenter->offer($offer))->values(),
                'history' => $past->map(fn (MedicineSupplierOffer $offer) => $this->presenter->offer($offer))->values(),
            ],
        ]);
    }

    public function update(Request $request, string $supplierUuid, UpdateMedicineSupplierAction $action): JsonResponse
    {
        $this->authorizeActor($request, 'medicine_suppliers.update');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);

        $supplier = $action->execute($this->supplier($supplierUuid), $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Fournisseur mis à jour.', 'data' => $this->presenter->identity($supplier)]);
    }

    public function archive(Request $request, string $supplierUuid, ArchiveMedicineSupplierAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $supplier = $action->execute($this->supplier($supplierUuid), $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "{$supplier->name} a été archivé."]);
    }

    public function restore(Request $request, string $supplierUuid, RestoreMedicineSupplierAction $action): JsonResponse
    {
        $supplier = MedicineSupplier::onlyTrashed()->where('uuid', $supplierUuid)->firstOrFail();
        $action->execute($supplier, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "{$supplier->name} a été restauré."]);
    }

    /** Says what each line of a supplier file would do, without writing. */
    public function previewSuppliersImport(Request $request, MedicineSupplierImportService $service): JsonResponse
    {
        $this->authorizeActor($request, 'medicine_suppliers.import');

        return response()->json(['data' => $service->plan($this->importRows($request))]);
    }

    public function importSuppliers(Request $request, MedicineSupplierImportService $service): JsonResponse
    {
        $result = $service->import($this->importRows($request), CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => sprintf(
                'Import terminé : %d fournisseur(s) créé(s), %d mis à jour, %d inchangé(s).',
                $result['created'],
                $result['updated'],
                $result['unchanged'],
            ),
            'data' => $result,
        ]);
    }

    public function catalogs(Request $request, string $supplierUuid): JsonResponse
    {
        $this->authorizeActor($request, ['supplier_catalogs.view', 'medicine_suppliers.view']);
        // An archived folder stays readable, so it can be checked and restored.
        $supplier = $this->supplier($supplierUuid, withArchived: true);

        return response()->json([
            'data' => [
                'supplier' => $this->presenter->identity($supplier),
                'catalogs' => $supplier->catalogs()
                    ->withTrashed()
                    ->withCount('items')
                    ->with('creator:id,name')
                    ->latest('created_at')
                    ->get()
                    ->map(fn (SupplierCatalog $catalog) => $this->presenter->catalog($catalog))
                    ->values(),
            ],
        ]);
    }

    /** The only site endpoint receiving a binary file from the portal. */
    public function uploadCatalog(Request $request, string $supplierUuid, UploadSupplierCatalogAction $action): JsonResponse
    {
        $supplier = $this->supplier($supplierUuid);
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,pdf', 'max:10240'],
            'catalog_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $catalog = $action->execute($supplier, $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json([
            'message' => 'Catalogue ajouté au dossier du fournisseur.',
            'data' => $this->presenter->catalog($catalog->loadCount('items')),
        ], 201);
    }

    public function activateCatalog(Request $request, string $supplierUuid, string $catalogUuid, ActivateSupplierCatalogAction $action): JsonResponse
    {
        $catalog = $action->execute($this->catalog($supplierUuid, $catalogUuid), CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Catalogue activé.', 'data' => ['uuid' => $catalog->uuid]]);
    }

    /** ADR-098 — what a catalog file contains, line by line, without opening it. */
    public function catalogItems(Request $request, string $supplierUuid, string $catalogUuid): JsonResponse
    {
        $this->authorizeActor($request, ['supplier_catalogs.view', 'medicine_suppliers.view']);
        $supplier = $this->supplier($supplierUuid, withArchived: true);
        $catalog = $supplier->catalogs()->withTrashed()->where('uuid', $catalogUuid)->withCount('items')->firstOrFail();

        return response()->json(['data' => [
            'supplier' => $this->presenter->identity($supplier),
            'catalog' => $this->presenter->catalog($catalog),
            // Withdrawn lines travel too, flagged: the portal must be able
            // to show them in order to restore them (ADR-009).
            'items' => $catalog->items()->withTrashed()
                ->with('linkedMedicine.catalogItem:id,code,name')
                ->orderBy('row_number')
                ->get()
                ->map(fn ($item) => [
                    'uuid' => $item->uuid,
                    'row_number' => $item->row_number,
                    'reference' => $item->reference,
                    'medicine_label' => $item->medicine_label,
                    'presentation' => $item->presentation,
                    'family_label' => $item->family_label,
                    'supplier_price' => $item->supplier_price,
                    'linked_medicine_name' => $item->linkedMedicine?->catalogItem?->name,
                    'linked_medicine_code' => $item->linkedMedicine?->catalogItem?->code,
                    'archived' => $item->trashed(),
                    'delete_reason' => $item->delete_reason,
                ])
                ->values(),
        ]]);
    }

    /**
     * ADR-098 — correcting, withdrawing, restoring or unlinking one line of
     * a supplier catalogue, from the portal. Same Actions as the clinic:
     * the rules, the audit and the permissions are the site's own.
     */
    public function updateCatalogItem(Request $request, string $supplierUuid, string $catalogUuid, string $itemUuid, UpdateSupplierCatalogItemAction $action): JsonResponse
    {
        $item = $this->catalogItem($supplierUuid, $catalogUuid, $itemUuid);
        $validated = $request->validate((new UpdateSupplierCatalogItemRequest)->rules());
        $action->execute($item, $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Ligne de catalogue corrigée.']);
    }

    public function archiveCatalogItem(Request $request, string $supplierUuid, string $catalogUuid, string $itemUuid, ArchiveSupplierCatalogItemAction $action): JsonResponse
    {
        $item = $this->catalogItem($supplierUuid, $catalogUuid, $itemUuid);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->execute($item, $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Ligne mise à la corbeille.']);
    }

    public function restoreCatalogItem(Request $request, string $supplierUuid, string $catalogUuid, string $itemUuid, RestoreSupplierCatalogItemAction $action): JsonResponse
    {
        $item = $this->catalogItem($supplierUuid, $catalogUuid, $itemUuid, withArchived: true);
        $action->execute($item, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Ligne restaurée.']);
    }

    public function unlinkCatalogItem(Request $request, string $supplierUuid, string $catalogUuid, string $itemUuid, UnlinkSupplierCatalogItemAction $action): JsonResponse
    {
        $item = $this->catalogItem($supplierUuid, $catalogUuid, $itemUuid);
        $action->execute($item, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Rattachement défait ; le prix fournisseur qu’il portait est clos.']);
    }

    /** A line's uuid is public: it must belong to that catalogue of that folder. */
    private function catalogItem(string $supplierUuid, string $catalogUuid, string $itemUuid, bool $withArchived = false): SupplierCatalogItem
    {
        $catalog = $this->supplier($supplierUuid, withArchived: true)
            ->catalogs()->withTrashed()->where('uuid', $catalogUuid)->firstOrFail();

        return $catalog->items()
            ->when($withArchived, fn ($query) => $query->withTrashed())
            ->where('uuid', $itemUuid)
            ->firstOrFail();
    }

    public function updateCatalog(Request $request, string $supplierUuid, string $catalogUuid, UpdateSupplierCatalogAction $action): JsonResponse
    {
        $validated = $request->validate(['catalog_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $catalog = $action->execute($this->catalog($supplierUuid, $catalogUuid), $validated, CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Catalogue mis à jour.', 'data' => ['uuid' => $catalog->uuid]]);
    }

    public function archiveCatalog(Request $request, string $supplierUuid, string $catalogUuid, ArchiveSupplierCatalogAction $action): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->execute($this->catalog($supplierUuid, $catalogUuid), $validated['reason'], CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => 'Catalogue archivé.']);
    }

    public function restoreCatalog(Request $request, string $supplierUuid, string $catalogUuid, RestoreSupplierCatalogAction $action): JsonResponse
    {
        $catalog = $action->execute(
            $this->catalog($supplierUuid, $catalogUuid, archived: true),
            CatalogActor::fromRemoteRequest($request),
        );

        return response()->json(['message' => 'Catalogue restauré.', 'data' => ['uuid' => $catalog->uuid]]);
    }

    public function previewImport(Request $request, string $supplierUuid, string $catalogUuid, SupplierCatalogImportService $service): JsonResponse
    {
        $this->authorizeActor($request, 'supplier_catalogs.create');
        $catalog = $this->catalog($supplierUuid, $catalogUuid)->loadCount('items');

        return response()->json([
            'data' => [
                'supplier' => $this->presenter->identity($catalog->supplier),
                'catalog' => $this->presenter->catalog($catalog),
                'preview' => $service->preview($catalog),
            ],
        ]);
    }

    public function import(Request $request, string $supplierUuid, string $catalogUuid, SupplierCatalogImportService $service): JsonResponse
    {
        $this->authorizeActor($request, 'supplier_catalogs.create');
        $result = $service->import($this->catalog($supplierUuid, $catalogUuid), CatalogActor::fromRemoteRequest($request));

        return response()->json(['message' => "{$result['rows']} ligne(s) importée(s).", 'data' => $result]);
    }

    private function supplier(string $uuid, bool $withArchived = false): MedicineSupplier
    {
        return MedicineSupplier::query()
            ->when($withArchived, fn ($query) => $query->withTrashed())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function importRows(Request $request): array
    {
        return $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.MedicineSupplierImportService::MAX_ROWS],
            'rows.*' => ['array'],
            'rows.*.line' => ['nullable', 'integer', 'min:1'],
            'rows.*.code' => ['nullable'],
            'rows.*.name' => ['nullable'],
            'rows.*.contact_name' => ['nullable'],
            'rows.*.phone' => ['nullable'],
            'rows.*.email' => ['nullable'],
            'rows.*.address' => ['nullable'],
        ])['rows'];
    }

    private function catalog(string $supplierUuid, string $catalogUuid, bool $archived = false): SupplierCatalog
    {
        return $this->supplier($supplierUuid)->catalogs()
            ->when($archived, fn ($query) => $query->onlyTrashed())
            ->where('uuid', $catalogUuid)
            ->firstOrFail();
    }

    /** @param string|array<int, string> $permissions any one of them is enough */
    private function authorizeActor(Request $request, string|array $permissions): void
    {
        $actor = CatalogActor::fromRemoteRequest($request);

        if (collect((array) $permissions)->every(fn (string $permission) => $actor->cannot($permission))) {
            throw new AuthorizationException('Cette action distante n’est pas autorisée.');
        }
    }
}
