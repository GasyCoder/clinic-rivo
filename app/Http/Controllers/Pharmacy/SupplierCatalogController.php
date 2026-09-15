<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Pharmacy\ActivateSupplierCatalogAction;
use App\Actions\Pharmacy\ArchiveSupplierCatalogAction;
use App\Actions\Pharmacy\LinkSupplierCatalogItemAction;
use App\Actions\Pharmacy\RestoreSupplierCatalogAction;
use App\Actions\Pharmacy\UpdateSupplierCatalogAction;
use App\Actions\Pharmacy\UploadSupplierCatalogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\ArchiveSupplierCatalogRequest;
use App\Http\Requests\Pharmacy\LinkSupplierCatalogItemRequest;
use App\Http\Requests\Pharmacy\StoreSupplierCatalogRequest;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\SupplierCatalog;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierCatalogImportService;
use App\Services\Pharmacy\SupplierPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierCatalogController extends Controller
{
    public function store(StoreSupplierCatalogRequest $request, MedicineSupplier $supplier, UploadSupplierCatalogAction $action): RedirectResponse
    {
        $action->execute($supplier, $request->validated(), CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Catalogue ajouté au dossier du fournisseur.');
    }

    public function show(Request $request, MedicineSupplier $supplier, SupplierCatalog $catalog): StreamedResponse
    {
        abort_unless($request->user()?->can('view-supplier-catalogs'), 403);
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);
        abort_unless(Storage::disk('local')->exists($catalog->path), 404);

        return Storage::disk('local')->response($catalog->path, $this->safeInlineName($catalog->original_name), [
            'Content-Type' => $catalog->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ], 'inline');
    }

    public function download(Request $request, MedicineSupplier $supplier, SupplierCatalog $catalog): StreamedResponse
    {
        abort_unless($request->user()?->can('view-supplier-catalogs'), 403);
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);
        abort_unless(Storage::disk('local')->exists($catalog->path), 404);

        return Storage::disk('local')->download($catalog->path, $this->safeInlineName($catalog->original_name), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function activate(Request $request, MedicineSupplier $supplier, SupplierCatalog $catalog, ActivateSupplierCatalogAction $action): RedirectResponse
    {
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);
        $action->execute($catalog, CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Catalogue activé.');
    }

    public function update(Request $request, MedicineSupplier $supplier, SupplierCatalog $catalog, UpdateSupplierCatalogAction $action): RedirectResponse
    {
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);
        $validated = $request->validate(['catalog_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $action->execute($catalog, $validated, CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Catalogue mis à jour.');
    }

    /** ADR-098 — shows what the file contains before anything is written. */
    public function preview(MedicineSupplier $supplier, SupplierCatalog $catalog, SupplierCatalogImportService $service, SupplierPresenter $presenter): Response
    {
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);

        $catalog->loadCount('items');

        return Inertia::render('Pharmacy/Suppliers/ImportPreview', [
            'supplier' => $presenter->identity($supplier),
            'catalog' => $presenter->catalog($catalog),
            'preview' => $service->preview($catalog),
        ]);
    }

    public function import(Request $request, MedicineSupplier $supplier, SupplierCatalog $catalog, SupplierCatalogImportService $service): RedirectResponse
    {
        abort_unless($request->user()?->can('supplier_catalogs.create'), 403);
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);

        $result = $service->import($catalog, CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.suppliers.catalogs.items', [$supplier, $catalog])
            ->with('status', "{$result['rows']} ligne(s) importée(s).");
    }

    public function linkItem(
        LinkSupplierCatalogItemRequest $request,
        MedicineSupplier $supplier,
        SupplierCatalogItem $catalogItem,
        LinkSupplierCatalogItemAction $action,
    ): RedirectResponse {
        abort_unless($catalogItem->catalog->medicine_supplier_id === $supplier->id, 404);

        $medicine = Medicine::query()->where('uuid', $request->validated('medicine_uuid'))->firstOrFail();
        $action->execute($catalogItem, $medicine, $request->validated('change_reason'), $request->user());

        return back()->with('status', 'Produit lié au catalogue clinique avec son prix fournisseur.');
    }

    public function destroy(ArchiveSupplierCatalogRequest $request, MedicineSupplier $supplier, SupplierCatalog $catalog, ArchiveSupplierCatalogAction $action): RedirectResponse
    {
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);
        $action->execute($catalog, $request->validated('reason'), CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Catalogue archivé.');
    }

    public function restore(Request $request, MedicineSupplier $supplier, SupplierCatalog $catalog, RestoreSupplierCatalogAction $action): RedirectResponse
    {
        abort_unless($catalog->medicine_supplier_id === $supplier->id, 404);
        $action->execute($catalog, CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Catalogue restauré.');
    }

    private function safeInlineName(string $originalName): string
    {
        $name = basename(str_replace('\\', '/', $originalName));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $name) ?: 'catalogue';

        return Str::limit($name, 255, '');
    }
}
