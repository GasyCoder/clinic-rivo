<?php

namespace App\Http\Controllers\Pharmacy;

use App\Actions\Catalog\SetCatalogTariffAction;
use App\Actions\Pharmacy\CreateMedicineProductAction;
use App\Actions\Pharmacy\LinkSupplierCatalogItemAction;
use App\Actions\Pharmacy\ManageMedicineCategoryAction;
use App\Actions\Pharmacy\SetMedicineActiveAction;
use App\Actions\Pharmacy\UpdateMedicineProductAction;
use App\Enums\CatalogTariffCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pharmacy\ImportMedicineCatalogRequest;
use App\Http\Requests\Pharmacy\StoreMedicineCategoryRequest;
use App\Http\Requests\Pharmacy\StoreMedicineProductRequest;
use App\Http\Requests\Pharmacy\UpdateMedicineProductRequest;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\MedicineCatalogImportService;
use App\Services\Pharmacy\MedicineCatalogPresenter;
use App\Services\Pharmacy\PharmacyWorkspaceService;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The clinic catalog: what the clinic has decided to stock and sell. It is
 * never the same thing as what a supplier offers (ADR-097, ADR-098).
 */
class MedicineController extends Controller
{
    /** ADR-098 — merged into « Médicaments & stock ». */
    public function index(): RedirectResponse
    {
        return to_route('pharmacy.stock.index');
    }

    public function create(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/Medicines/Create', $workspace->medicineForm($request->user()));
    }

    public function store(
        StoreMedicineProductRequest $request,
        CreateMedicineProductAction $action,
        LinkSupplierCatalogItemAction $link,
    ): RedirectResponse {
        $itemUuid = $request->validated('supplier_catalog_item_uuid');
        $item = $itemUuid
            ? SupplierCatalogItem::query()->with('catalog.supplier')->where('uuid', $itemUuid)->firstOrFail()
            : null;

        // Adding the medicine and linking the supplier line it came from are
        // one decision: if the link is refused, no half-added medicine remains.
        $medicine = DB::transaction(function () use ($request, $action, $link, $item): Medicine {
            $medicine = $action->execute(
                Arr::except($request->validated(), ['supplier_catalog_item_uuid']),
                $request->user(),
            );

            if ($item) {
                $link->execute($item, $medicine, 'Ajout au catalogue clinique depuis le catalogue fournisseur', CatalogActor::fromUser($request->user()));
            }

            return $medicine;
        });

        if ($item) {
            return to_route('pharmacy.suppliers.catalogs.items', [$item->catalog->supplier, $item->catalog])
                ->with('status', "{$medicine->catalogItem->name} ajouté au catalogue et rattaché au prix du fournisseur.");
        }

        return to_route('pharmacy.medicines.index')
            ->with('status', "Médicament {$medicine->catalogItem->code} ajouté au catalogue avec son prix de vente.");
    }

    public function edit(Request $request, Medicine $medicine, PharmacyWorkspaceService $workspace, MedicineCatalogPresenter $presenter): Response
    {
        $user = $request->user();

        return Inertia::render('Pharmacy/Medicines/Edit', [
            ...$workspace->medicineForm($user),
            'medicine' => $presenter->medicine($medicine),
            'can' => [
                'change_price' => $user->can('catalog.tariffs.update'),
                'deactivate' => $user->can('medicines.delete'),
                'reactivate' => $user->can('medicines.restore'),
            ],
        ]);
    }

    public function update(UpdateMedicineProductRequest $request, Medicine $medicine, UpdateMedicineProductAction $action): RedirectResponse
    {
        $medicine = $action->execute($medicine, $request->validated(), CatalogActor::fromUser($request->user()));

        return to_route('pharmacy.stock.index')->with('status', "Médicament {$medicine->catalogItem->name} mis à jour.");
    }

    /**
     * ADR-170 — the sale price only: the first one needs no reason, a change
     * does, because the previous price stays in the history (ADR-024).
     */
    public function updateSalePrice(Request $request, Medicine $medicine, SetCatalogTariffAction $action): RedirectResponse
    {
        $medicine->loadMissing('catalogItem');
        $hasPrice = $medicine->catalogItem->currentStandardTariff !== null;

        $validated = $request->validate([
            'sale_price' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'reason' => [$hasPrice ? 'required' : 'nullable', 'string', 'min:3', 'max:1000'],
        ], [
            'sale_price.gt' => 'Le prix de vente doit être supérieur à zéro.',
            'reason.required' => 'Indiquez pourquoi le prix change : l’ancien prix reste dans l’historique.',
        ]);

        $action->execute(
            $medicine->catalogItem,
            CatalogTariffCategory::Standard,
            (string) $validated['sale_price'],
            filled($validated['reason'] ?? null) ? $validated['reason'] : 'Premier prix de vente fixé par la Pharmacie',
            CatalogActor::fromUser($request->user()),
        );

        return back()->with('status', "Prix de vente de {$medicine->catalogItem->name} enregistré.");
    }

    public function deactivate(Request $request, Medicine $medicine, SetMedicineActiveAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->execute($medicine, false, $validated['reason'], CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Médicament désactivé : il n’est plus proposé à la vente, aux commandes ni aux entrées.');
    }

    public function reactivate(Request $request, Medicine $medicine, SetMedicineActiveAction $action): RedirectResponse
    {
        $action->execute($medicine, true, null, CatalogActor::fromUser($request->user()));

        return back()->with('status', 'Médicament réactivé.');
    }

    public function storeCategory(StoreMedicineCategoryRequest $request, ManageMedicineCategoryAction $action): RedirectResponse
    {
        $category = $action->create($request->validated(), CatalogActor::fromUser($request->user()));

        return back()->with('status', "Famille {$category->name} créée.");
    }

    public function updateCategory(Request $request, MedicineCategory $category, ManageMedicineCategoryAction $action): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000']]);
        $category = $action->update($category, $validated, CatalogActor::fromUser($request->user()));

        return back()->with('status', "Famille {$category->name} renommée.");
    }

    public function archiveCategory(Request $request, MedicineCategory $category, ManageMedicineCategoryAction $action): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $action->archive($category, $validated['reason'], CatalogActor::fromUser($request->user()));

        return back()->with('status', "Famille {$category->name} archivée.");
    }

    public function restoreCategory(Request $request, MedicineCategory $category, ManageMedicineCategoryAction $action): RedirectResponse
    {
        $action->restore($category, CatalogActor::fromUser($request->user()));

        return back()->with('status', "Famille {$category->name} restaurée.");
    }

    public function template(Request $request, ExcelWorkbook $workbook): StreamedResponse
    {
        abort_unless($request->user()->can('medicines.import'), 403);

        return $workbook->download('modele-import-medicaments', 'Médicaments', MedicineCatalogImportService::HEADERS, []);
    }

    public function import(ImportMedicineCatalogRequest $request, MedicineCatalogImportService $importer): RedirectResponse
    {
        $result = $importer->import($request->file('file'), $request->user());

        return back()->with('status', "Import terminé : {$result['rows']} médicament(s) ajouté(s).");
    }
}
