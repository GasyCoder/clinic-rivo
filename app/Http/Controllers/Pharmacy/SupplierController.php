<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\CreateMedicineSupplierAction;
use App\Enums\SupplierCatalogFileKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\StoreMedicineSupplierRequest;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-098 — the supplier "folder": suppliers shown as folders, then one
 * folder whose content (catalogs, orders, invoices, prices) is shown as
 * sub-folders, each opened only with its own permission.
 */
class SupplierController extends Controller
{
    public function __construct(private readonly SupplierPresenter $presenter) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Pharmacy/Suppliers/Index', [
            'suppliers' => MedicineSupplier::query()
                ->withCount('catalogs')
                ->orderBy('name')
                ->get()
                ->map(fn (MedicineSupplier $supplier) => [
                    ...$this->presenter->identity($supplier),
                    'catalogs_count' => $supplier->catalogs_count,
                ])
                ->values(),
            'can' => ['create' => $request->user()->can('medicine_suppliers.create')],
        ]);
    }

    public function store(StoreMedicineSupplierRequest $request, CreateMedicineSupplierAction $action): RedirectResponse
    {
        $supplier = $action->execute($request->validated(), CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.suppliers.show', $supplier)
            ->with('status', "Dossier du fournisseur {$supplier->name} créé.");
    }

    public function show(Request $request, MedicineSupplier $supplier): Response
    {
        $user = $request->user();

        return Inertia::render('Pharmacy/Suppliers/Show', [
            'supplier' => $this->presenter->identity($supplier),
            'counts' => $this->presenter->folderCounts($supplier),
            'can' => [
                'view_catalogs' => $user->can('view-supplier-catalogs'),
                'view_orders' => $user->can('view-supplier-orders'),
                'view_invoices' => $user->can('view-supplier-invoices'),
                'view_offers' => $user->can('view-supplier-offers'),
                'create_order' => $user->can('purchase_orders.create'),
            ],
        ]);
    }

    public function catalogs(Request $request, MedicineSupplier $supplier): Response
    {
        $user = $request->user();

        return Inertia::render('Pharmacy/Suppliers/Catalogs', [
            'supplier' => $this->presenter->identity($supplier),
            'catalogs' => $supplier->catalogs()
                ->withTrashed()
                ->withCount('items')
                ->with('creator:id,name')
                ->latest('created_at')
                ->get()
                ->map(fn (SupplierCatalog $catalog) => $this->presenter->catalog($catalog))
                ->values(),
            'can' => [
                'create' => $user->can('supplier_catalogs.create'),
                'update' => $user->can('supplier_catalogs.update'),
                'delete' => $user->can('supplier_catalogs.delete'),
                'restore' => $user->can('supplier_catalogs.restore'),
            ],
        ]);
    }

    public function catalogItems(Request $request, MedicineSupplier $supplier, SupplierCatalog $catalog): Response
    {
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);
        abort_unless($catalog->kind === SupplierCatalogFileKind::Excel, 404);

        $user = $request->user();
        $canLink = $user->can('medicine_supplier_offers.create') || $user->can('medicine_supplier_offers.update');
        // ADR-024 stays in force: creating a clinic medicine from a supplier
        // line needs the same rights as creating it anywhere else.
        $canAddToCatalog = $canLink
            && $user->can('medicines.create')
            && $user->can('catalog.items.create')
            && $user->can('catalog.tariffs.create');

        $catalog->loadCount('items')->load('creator:id,name');

        return Inertia::render('Pharmacy/Suppliers/CatalogItems', [
            'supplier' => $this->presenter->identity($supplier),
            'catalog' => $this->presenter->catalog($catalog),
            'items' => $catalog->items()
                ->with('linkedMedicine.catalogItem:id,code,name')
                ->orderBy('row_number')
                ->get()
                ->map(fn (SupplierCatalogItem $item) => [
                    'uuid' => $item->uuid,
                    'reference' => $item->reference,
                    'medicine_label' => $item->medicine_label,
                    'presentation' => $item->presentation,
                    'supplier_price' => $item->supplier_price,
                    'linked_medicine_uuid' => $item->linkedMedicine?->uuid,
                    'linked_medicine_name' => $item->linkedMedicine?->catalogItem?->name,
                ])
                ->values(),
            'medicines' => $canLink
                ? Medicine::query()->where('active', true)
                    ->with('catalogItem:id,code,name')
                    ->get()
                    ->map(fn (Medicine $medicine) => [
                        'uuid' => $medicine->uuid,
                        'code' => $medicine->catalogItem?->code,
                        'name' => $medicine->catalogItem?->name,
                    ])
                    ->sortBy('name')
                    ->values()
                : [],
            'can' => ['link' => $canLink, 'add_to_catalog' => $canAddToCatalog],
        ]);
    }

    public function products(MedicineSupplier $supplier): Response
    {
        [$current, $past] = $supplier->offers()
            ->with('medicine.catalogItem:id,code,name')
            ->orderByDesc('effective_from')
            ->get()
            ->partition(fn (MedicineSupplierOffer $offer) => $offer->isCurrent());

        return Inertia::render('Pharmacy/Suppliers/Products', [
            'supplier' => $this->presenter->identity($supplier),
            'offers' => $current->map(fn (MedicineSupplierOffer $offer) => $this->presenter->offer($offer))->values(),
            'history' => $past->map(fn (MedicineSupplierOffer $offer) => $this->presenter->offer($offer))->values(),
        ]);
    }
}
