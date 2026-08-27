<?php

namespace App\Http\Controllers;

use App\Actions\Pharmacy\AdjustMedicineStockAction;
use App\Actions\Pharmacy\CreateExternalDispenseAction;
use App\Actions\Pharmacy\CreateMedicineCategoryAction;
use App\Actions\Pharmacy\CreateMedicineProductAction;
use App\Actions\Pharmacy\CreateMedicineSupplierAction;
use App\Actions\Pharmacy\DispenseMedicinesAction;
use App\Actions\Pharmacy\PrepareDispenseInvoiceAction;
use App\Actions\Pharmacy\RecordStockEntryAction;
use App\Http\Requests\Pharmacy\DispenseMedicinesRequest;
use App\Http\Requests\Pharmacy\ImportMedicineCatalogRequest;
use App\Http\Requests\Pharmacy\StoreExternalDispenseRequest;
use App\Http\Requests\Pharmacy\StoreMedicineCategoryRequest;
use App\Http\Requests\Pharmacy\StoreMedicineProductRequest;
use App\Http\Requests\Pharmacy\StoreMedicineSupplierRequest;
use App\Http\Requests\Pharmacy\StoreStockAdjustmentRequest;
use App\Http\Requests\Pharmacy\StoreStockEntryRequest;
use App\Models\PharmacyDispense;
use App\Services\Pharmacy\MedicineCatalogImportService;
use App\Services\Pharmacy\PharmacyWorkspaceService;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PharmacyController extends Controller
{
    public function __invoke(Request $request, PharmacyWorkspaceService $workspace): Response
    {
        return Inertia::render('Pharmacy/Index', $workspace->for($request->user()));
    }

    public function createExternalDispense(
        Request $request,
        PharmacyWorkspaceService $workspace,
    ): Response {
        return Inertia::render(
            'Pharmacy/CounterSales/Create',
            $workspace->externalCounterSaleFor($request->user()),
        );
    }

    public function storeEntry(
        StoreStockEntryRequest $request,
        RecordStockEntryAction $action,
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return back()->with('status', 'L’entrée de stock a été enregistrée et auditée.');
    }

    public function storeAdjustment(
        StoreStockAdjustmentRequest $request,
        AdjustMedicineStockAction $action,
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return back()->with('status', 'L’ajustement de stock a été enregistré et audité.');
    }

    public function storeExternalDispense(
        StoreExternalDispenseRequest $request,
        CreateExternalDispenseAction $action,
    ): RedirectResponse {
        $dispense = $action->execute($request->validated(), $request->user());

        return to_route('pharmacy.index', ['tab' => 'dispenses'])
            ->with('status', "Vente comptoir préparée. Facture {$dispense->invoice->invoice_number} transmise à la Caisse.");
    }

    public function prepareInvoice(
        Request $request,
        PharmacyDispense $dispense,
        PrepareDispenseInvoiceAction $action,
    ): RedirectResponse {
        Gate::forUser($request->user())->authorize('prepareInvoice', $dispense);
        $dispense = $action->execute($dispense, $request->user());

        return back()->with('status', "Facture {$dispense->invoice->invoice_number} transmise à la Caisse.");
    }

    public function dispense(
        DispenseMedicinesRequest $request,
        PharmacyDispense $dispense,
        DispenseMedicinesAction $action,
    ): RedirectResponse {
        $event = $action->execute($dispense, $request->validated(), $request->user());

        return back()->with('status', "Bon de sortie {$event->delivery_number} enregistré. Le stock a été décrémenté.");
    }

    public function storeCategory(
        StoreMedicineCategoryRequest $request,
        CreateMedicineCategoryAction $action,
    ): RedirectResponse {
        $category = $action->execute($request->validated(), $request->user());

        return back()->with('status', "Catégorie {$category->code} créée.");
    }

    public function storeSupplier(
        StoreMedicineSupplierRequest $request,
        CreateMedicineSupplierAction $action,
    ): RedirectResponse {
        $supplier = $action->execute($request->validated(), $request->user());

        return back()->with('status', "Fournisseur {$supplier->code} créé.");
    }

    public function storeMedicine(
        StoreMedicineProductRequest $request,
        CreateMedicineProductAction $action,
    ): RedirectResponse {
        $medicine = $action->execute($request->validated(), $request->user());

        return back()->with('status', "Médicament {$medicine->catalogItem->code} créé avec son tarif de vente.");
    }

    public function catalogTemplate(Request $request, ExcelWorkbook $workbook): StreamedResponse
    {
        abort_unless($request->user()->can('medicines.import'), 403);

        return $workbook->download(
            'modele-import-medicaments',
            'Médicaments',
            MedicineCatalogImportService::HEADERS,
            [],
        );
    }

    public function importCatalog(
        ImportMedicineCatalogRequest $request,
        MedicineCatalogImportService $importer,
    ): RedirectResponse {
        $result = $importer->import($request->file('file'), $request->user());

        return back()->with('status', "Import terminé : {$result['rows']} médicament(s) créé(s).");
    }
}
