<?php

use App\Enums\ReceptionPatientStep;
use App\Http\Controllers\Administration\AnalysisCatalogController;
use App\Http\Controllers\Administration\AttendanceController;
use App\Http\Controllers\Administration\CashRegisterController;
use App\Http\Controllers\Administration\CatalogController as AdministrationCatalogController;
use App\Http\Controllers\Administration\DiagnosticCatalogController;
use App\Http\Controllers\Administration\EmployeeController;
use App\Http\Controllers\Administration\EmploymentContractController;
use App\Http\Controllers\Administration\GeneratedDocumentController;
use App\Http\Controllers\Administration\HrDocumentController;
use App\Http\Controllers\Administration\HrReferenceController;
use App\Http\Controllers\Administration\HrReportController;
use App\Http\Controllers\Administration\LeaveController;
use App\Http\Controllers\Administration\PlanningController;
use App\Http\Controllers\Administration\StaffBlockCreditController;
use App\Http\Controllers\Administration\UserController as AdministrationUserController;
use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\AnesthesiaController;
use App\Http\Controllers\AnesthesiaWorkspaceController;
use App\Http\Controllers\AttentionDigestController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CareController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\DiagnosticCatalogSearchController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\EpisodeEmergencyController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LaboratoryController;
use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\MaternityController;
use App\Http\Controllers\Medicine\ParaclinicalRequestDirectoryController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientMutualCoverageAttachmentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Pharmacy\CareConsumableController as PharmacyCareConsumableController;
use App\Http\Controllers\Pharmacy\CounterSaleController as PharmacyCounterSaleController;
use App\Http\Controllers\Pharmacy\DashboardController as PharmacyDashboardController;
use App\Http\Controllers\Pharmacy\DispenseController as PharmacyDispenseController;
use App\Http\Controllers\Pharmacy\GoodsReceiptController;
use App\Http\Controllers\Pharmacy\MedicineController as PharmacyMedicineController;
use App\Http\Controllers\Pharmacy\MedicineSupplierOfferController;
use App\Http\Controllers\Pharmacy\PurchaseOrderController;
use App\Http\Controllers\Pharmacy\PurchasesController as PharmacyPurchasesController;
use App\Http\Controllers\Pharmacy\StockController as PharmacyStockController;
use App\Http\Controllers\Pharmacy\SupplierCatalogController;
use App\Http\Controllers\Pharmacy\SupplierCatalogTemplateController;
use App\Http\Controllers\Pharmacy\SupplierController;
use App\Http\Controllers\Pharmacy\SupplierInvoiceController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\Reception\EmployeePatientLookupController;
use App\Http\Controllers\Reception\EpisodeFinancialContextController;
use App\Http\Controllers\Reception\EpisodeServiceController;
use App\Http\Controllers\Reception\EpisodeSettlementController;
use App\Http\Controllers\Reception\ReceptionEstimateController;
use App\Http\Controllers\ReceptionController;
use App\Http\Controllers\SuperAdmin\AddressEntryController as SuperAdminAddressEntryController;
use App\Http\Controllers\SuperAdmin\AnalysisCatalogController as SuperAdminAnalysisCatalogController;
use App\Http\Controllers\SuperAdmin\CashRegisterController as SuperAdminCashRegisterController;
use App\Http\Controllers\SuperAdmin\CatalogController as SuperAdminCatalogController;
use App\Http\Controllers\SuperAdmin\DocumentTemplateController as SuperAdminDocumentTemplateController;
use App\Http\Controllers\SuperAdmin\HumanResourcesController as SuperAdminHumanResourcesController;
use App\Http\Controllers\SuperAdmin\MedicineStockController as SuperAdminMedicineStockController;
use App\Http\Controllers\SuperAdmin\MutualOrganizationController as SuperAdminMutualOrganizationController;
use App\Http\Controllers\SuperAdmin\PaymentMethodController as SuperAdminPaymentMethodController;
use App\Http\Controllers\SuperAdmin\PharmacyCatalogController as SuperAdminPharmacyCatalogController;
use App\Http\Controllers\SuperAdmin\PharmacyProcurementController as SuperAdminPharmacyProcurementController;
use App\Http\Controllers\SuperAdmin\PharmacySupplierController as SuperAdminPharmacySupplierController;
use App\Http\Controllers\SuperAdmin\RoleController as SuperAdminRoleController;
use App\Http\Controllers\SuperAdmin\TrashController as SuperAdminTrashController;
use App\Http\Controllers\SuperAdmin\UserController as SuperAdminUserController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\SurgeryController;
use App\Http\Controllers\SurgicalBlockEntryController;
use App\Http\Controllers\SurgicalBlockExitController;
use App\Http\Controllers\SurgicalCareNoteController;
use App\Http\Controllers\SurgicalComplicationController;
use App\Http\Controllers\SurgicalConsumableController;
use App\Http\Controllers\SurgicalInterventionController;
use App\Http\Controllers\SurgicalPostoperativeObservationController;
use App\Http\Controllers\SurgicalPreoperativeController;
use App\Http\Controllers\SurgicalReportController;
use App\Http\Controllers\SurgicalTeamMemberController;
use App\Http\Controllers\SurgicalTreatmentItemController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\VisitorReceptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('dashboard')->middleware('account.deployment');

Route::middleware(['site.type:clinic,admin', 'guest'])->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware(['site.type:clinic,admin', 'auth', 'account.active', 'account.deployment'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Portail central : navigation et vues de supervision uniquement. Les données
// métier seront lues/écrites via les API des sites, jamais via leurs bases.
Route::middleware(['site.type:admin', 'auth', 'account.active', 'account.deployment', 'can:super_admin.portal.view'])
    ->prefix('super-admin')
    ->name('super-admin.')
    ->group(function () {
        Route::get('/trash', [SuperAdminTrashController::class, 'index'])->name('trash.index')->middleware('can:trash.view');
        Route::delete('/trash/{site}/{category}/{uuid}', [SuperAdminTrashController::class, 'destroy'])->name('trash.force-delete')->middleware('can:trash.force_delete');
        Route::post('/trash/{site}/{category}/{uuid}/restore', [SuperAdminTrashController::class, 'restore'])->name('trash.restore')->middleware('can:trash.restore');

        Route::get('/sites/{site}', [SuperAdminController::class, 'site'])->name('sites.show')->middleware('can:sites.view');
        Route::get('/stock', SuperAdminMedicineStockController::class)->name('stock.index')->middleware('can:stock.view');
        Route::get('/stock/export', [SuperAdminMedicineStockController::class, 'export'])->name('stock.export')->middleware('can:stock.export');
        Route::get('/stock/import-template', [SuperAdminMedicineStockController::class, 'template'])->name('stock.import-template')->middleware('can:stock.import');
        Route::post('/stock/import', [SuperAdminMedicineStockController::class, 'import'])->name('stock.import')->middleware('can:stock.import');
        // ADR-098 — medicine records and families of one site, through its API.
        Route::get('/stock/{site}/medicines/{medicine}/edit', [SuperAdminPharmacyCatalogController::class, 'editMedicine'])->name('stock.medicines.edit')->middleware('can:medicines.update');
        Route::put('/stock/{site}/medicines/{medicine}', [SuperAdminPharmacyCatalogController::class, 'updateMedicine'])->name('stock.medicines.update')->middleware('can:medicines.update');
        Route::post('/stock/{site}/medicines/{medicine}/deactivate', [SuperAdminPharmacyCatalogController::class, 'deactivateMedicine'])->name('stock.medicines.deactivate')->middleware('can:medicines.delete');
        Route::post('/stock/{site}/medicines/{medicine}/reactivate', [SuperAdminPharmacyCatalogController::class, 'reactivateMedicine'])->name('stock.medicines.reactivate')->middleware('can:medicines.restore');
        Route::get('/stock/{site}/categories', [SuperAdminPharmacyCatalogController::class, 'categories'])->name('stock.categories.index')->middleware('can:medicine_categories.view');
        Route::post('/stock/{site}/categories', [SuperAdminPharmacyCatalogController::class, 'storeCategory'])->name('stock.categories.store')->middleware('can:medicine_categories.create');
        Route::put('/stock/{site}/categories/{category}', [SuperAdminPharmacyCatalogController::class, 'updateCategory'])->name('stock.categories.update')->middleware('can:medicine_categories.update');
        Route::delete('/stock/{site}/categories/{category}', [SuperAdminPharmacyCatalogController::class, 'archiveCategory'])->name('stock.categories.destroy')->middleware('can:medicine_categories.delete');
        Route::post('/stock/{site}/categories/{category}/restore', [SuperAdminPharmacyCatalogController::class, 'restoreCategory'])->name('stock.categories.restore')->middleware('can:medicine_categories.restore');
        // ADR-098 — supplier folders and catalogs, always through the site API.
        Route::get('/pharmacy-suppliers', [SuperAdminPharmacySupplierController::class, 'index'])->name('pharmacy-suppliers.index')->middleware('can:medicine_suppliers.view');
        Route::post('/pharmacy-suppliers', [SuperAdminPharmacySupplierController::class, 'store'])->name('pharmacy-suppliers.store')->middleware('can:medicine_suppliers.create');
        // Registered before /{site}/{supplier}, which would otherwise capture "import".
        Route::get('/pharmacy-suppliers/export', [SuperAdminPharmacySupplierController::class, 'export'])->name('pharmacy-suppliers.export')->middleware('can:medicine_suppliers.export');
        Route::get('/pharmacy-suppliers/import-template', [SuperAdminPharmacySupplierController::class, 'template'])->name('pharmacy-suppliers.import-template')->middleware('can:medicine_suppliers.import');
        Route::get('/pharmacy-suppliers/catalog-template', SupplierCatalogTemplateController::class)->name('pharmacy-suppliers.catalog-template')->middleware('can:supplier_catalogs.view');
        Route::post('/pharmacy-suppliers/import', [SuperAdminPharmacySupplierController::class, 'analyzeImport'])->name('pharmacy-suppliers.import.analyze')->middleware('can:medicine_suppliers.import');
        Route::get('/pharmacy-suppliers/import/{token}', [SuperAdminPharmacySupplierController::class, 'showImport'])->name('pharmacy-suppliers.import.show')->middleware('can:medicine_suppliers.import')->whereUuid('token');
        Route::post('/pharmacy-suppliers/import/{token}', [SuperAdminPharmacySupplierController::class, 'confirmImport'])->name('pharmacy-suppliers.import.confirm')->middleware('can:medicine_suppliers.import')->whereUuid('token');
        // Before /{site}/{supplier}: preparing an order spans every supplier.
        Route::get('/pharmacy-suppliers/{site}/commander', [SuperAdminPharmacyProcurementController::class, 'compare'])->name('pharmacy-suppliers.compare')->middleware('can:view-supplier-offers');
        Route::post('/pharmacy-suppliers/{site}/commander', [SuperAdminPharmacyProcurementController::class, 'storeOrders'])->name('pharmacy-suppliers.orders.store-many')->middleware('can:purchase_orders.create');
        Route::get('/pharmacy-suppliers/{site}/{supplier}', [SuperAdminPharmacySupplierController::class, 'show'])->name('pharmacy-suppliers.show')->middleware('can:medicine_suppliers.view');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/catalogs', [SuperAdminPharmacySupplierController::class, 'catalogs'])->name('pharmacy-suppliers.catalogs.index')->middleware('can:view-supplier-catalogs');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/orders', [SuperAdminPharmacySupplierController::class, 'orders'])->name('pharmacy-suppliers.orders')->middleware('can:view-supplier-orders');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/invoices', [SuperAdminPharmacySupplierController::class, 'invoices'])->name('pharmacy-suppliers.invoices')->middleware('can:view-supplier-invoices');
        // ADR-098 — orders and invoices written from the portal; receiving stays at the site.
        Route::get('/pharmacy-suppliers/{site}/{supplier}/orders/create', [SuperAdminPharmacyProcurementController::class, 'createOrder'])->name('pharmacy-suppliers.orders.create')->middleware('can:purchase_orders.create');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/orders', [SuperAdminPharmacyProcurementController::class, 'storeOrder'])->name('pharmacy-suppliers.orders.store')->middleware('can:purchase_orders.create');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/orders/{order}', [SuperAdminPharmacyProcurementController::class, 'showOrder'])->name('pharmacy-suppliers.orders.show')->middleware('can:view-supplier-orders');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/orders/{order}/edit', [SuperAdminPharmacyProcurementController::class, 'editOrder'])->name('pharmacy-suppliers.orders.edit')->middleware('can:purchase_orders.update');
        Route::put('/pharmacy-suppliers/{site}/{supplier}/orders/{order}', [SuperAdminPharmacyProcurementController::class, 'updateOrder'])->name('pharmacy-suppliers.orders.update')->middleware('can:purchase_orders.update');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/orders/{order}/submit', [SuperAdminPharmacyProcurementController::class, 'submitOrder'])->name('pharmacy-suppliers.orders.submit')->middleware('can:purchase_orders.submit');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/orders/{order}/cancel', [SuperAdminPharmacyProcurementController::class, 'cancelOrder'])->name('pharmacy-suppliers.orders.cancel')->middleware('can:purchase_orders.cancel');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/invoices/create', [SuperAdminPharmacyProcurementController::class, 'createInvoice'])->name('pharmacy-suppliers.invoices.create')->middleware('can:supplier_invoices.create');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/invoices', [SuperAdminPharmacyProcurementController::class, 'storeInvoice'])->name('pharmacy-suppliers.invoices.store')->middleware('can:supplier_invoices.create');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/invoices/{invoice}', [SuperAdminPharmacyProcurementController::class, 'showInvoice'])->name('pharmacy-suppliers.invoices.show')->middleware('can:view-supplier-invoices');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/invoices/{invoice}/edit', [SuperAdminPharmacyProcurementController::class, 'editInvoice'])->name('pharmacy-suppliers.invoices.edit')->middleware('can:supplier_invoices.update');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/invoices/{invoice}/update', [SuperAdminPharmacyProcurementController::class, 'updateInvoice'])->name('pharmacy-suppliers.invoices.update')->middleware('can:supplier_invoices.update');
        Route::delete('/pharmacy-suppliers/{site}/{supplier}/invoices/{invoice}', [SuperAdminPharmacyProcurementController::class, 'archiveInvoice'])->name('pharmacy-suppliers.invoices.destroy')->middleware('can:supplier_invoices.delete');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/invoices/{invoice}/restore', [SuperAdminPharmacyProcurementController::class, 'restoreInvoice'])->name('pharmacy-suppliers.invoices.restore')->middleware('can:supplier_invoices.restore');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/products', [SuperAdminPharmacySupplierController::class, 'products'])->name('pharmacy-suppliers.products')->middleware('can:view-supplier-offers');
        Route::put('/pharmacy-suppliers/{site}/{supplier}', [SuperAdminPharmacySupplierController::class, 'update'])->name('pharmacy-suppliers.update')->middleware('can:medicine_suppliers.update');
        Route::delete('/pharmacy-suppliers/{site}/{supplier}', [SuperAdminPharmacySupplierController::class, 'archive'])->name('pharmacy-suppliers.archive')->middleware('can:medicine_suppliers.delete');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/restore', [SuperAdminPharmacySupplierController::class, 'restore'])->name('pharmacy-suppliers.restore')->middleware('can:medicine_suppliers.restore');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/catalogs', [SuperAdminPharmacySupplierController::class, 'uploadCatalog'])->name('pharmacy-suppliers.catalogs.store')->middleware('can:supplier_catalogs.create');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}/download', [SuperAdminPharmacySupplierController::class, 'downloadCatalog'])->name('pharmacy-suppliers.catalogs.download')->middleware('can:view-supplier-catalogs');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}/items', [SuperAdminPharmacySupplierController::class, 'catalogItems'])->name('pharmacy-suppliers.catalogs.items')->middleware('can:view-supplier-catalogs');
        Route::patch('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}', [SuperAdminPharmacySupplierController::class, 'updateCatalog'])->name('pharmacy-suppliers.catalogs.update')->middleware('can:supplier_catalogs.update');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}/activate', [SuperAdminPharmacySupplierController::class, 'activateCatalog'])->name('pharmacy-suppliers.catalogs.activate')->middleware('can:supplier_catalogs.update');
        Route::delete('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}', [SuperAdminPharmacySupplierController::class, 'archiveCatalog'])->name('pharmacy-suppliers.catalogs.destroy')->middleware('can:supplier_catalogs.delete');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}/restore', [SuperAdminPharmacySupplierController::class, 'restoreCatalog'])->name('pharmacy-suppliers.catalogs.restore')->middleware('can:supplier_catalogs.restore');
        Route::get('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}/import', [SuperAdminPharmacySupplierController::class, 'previewImport'])->name('pharmacy-suppliers.catalogs.import.preview')->middleware('can:supplier_catalogs.create');
        Route::post('/pharmacy-suppliers/{site}/{supplier}/catalogs/{catalog}/import', [SuperAdminPharmacySupplierController::class, 'import'])->name('pharmacy-suppliers.catalogs.import')->middleware('can:supplier_catalogs.create');
        Route::get('/addresses', [SuperAdminAddressEntryController::class, 'index'])->name('addresses.index')->middleware('can:address_entries.view');
        Route::get('/addresses/export', [SuperAdminAddressEntryController::class, 'export'])->name('addresses.export')->middleware('can:address_entries.export');
        Route::get('/addresses/import-template', [SuperAdminAddressEntryController::class, 'template'])->name('addresses.import-template')->middleware('can:address_entries.import');
        Route::post('/addresses/import', [SuperAdminAddressEntryController::class, 'import'])->name('addresses.import')->middleware('can:address_entries.import');
        Route::post('/addresses', [SuperAdminAddressEntryController::class, 'store'])->name('addresses.store')->middleware('can:address_entries.create');
        Route::post('/addresses/bulk/archive', [SuperAdminAddressEntryController::class, 'bulkArchive'])->name('addresses.bulk.archive')->middleware('can:address_entries.archive');
        Route::post('/addresses/bulk/restore', [SuperAdminAddressEntryController::class, 'bulkRestore'])->name('addresses.bulk.restore')->middleware(['can:trash.restore', 'can:address_entries.restore']);
        Route::put('/addresses/{site}/{address}', [SuperAdminAddressEntryController::class, 'update'])->name('addresses.update')->middleware('can:address_entries.update');
        Route::delete('/addresses/{site}/{address}', [SuperAdminAddressEntryController::class, 'destroy'])->name('addresses.destroy')->middleware('can:address_entries.archive');
        Route::post('/addresses/{site}/{address}/restore', [SuperAdminAddressEntryController::class, 'restore'])->name('addresses.restore')->middleware(['can:trash.restore', 'can:address_entries.restore']);

        Route::get('/analyses', [SuperAdminAnalysisCatalogController::class, 'index'])->name('analyses.index')->middleware('can:analysis_catalog.view');
        Route::get('/analyses/export', [SuperAdminAnalysisCatalogController::class, 'export'])->name('analyses.export')->middleware('can:analysis_catalog.export');
        Route::get('/analyses/import-template', [SuperAdminAnalysisCatalogController::class, 'template'])->name('analyses.import-template')->middleware('can:analysis_catalog.import');
        Route::post('/analyses/import', [SuperAdminAnalysisCatalogController::class, 'import'])->name('analyses.import')->middleware('can:analysis_catalog.import');
        Route::post('/analyses', [SuperAdminAnalysisCatalogController::class, 'store'])->name('analyses.store')->middleware('can:analysis_catalog.create');
        Route::get('/analyses/{site}/create', [SuperAdminAnalysisCatalogController::class, 'create'])->name('analyses.create')->middleware('can:analysis_catalog.create');
        Route::get('/analyses/{site}/{analysis}/edit', [SuperAdminAnalysisCatalogController::class, 'edit'])->name('analyses.edit')->middleware('can:analysis_catalog.update');
        Route::put('/analyses/{site}/{analysis}', [SuperAdminAnalysisCatalogController::class, 'update'])->name('analyses.update')->middleware('can:analysis_catalog.update');
        Route::post('/analyses/{site}/{analysis}/activate', [SuperAdminAnalysisCatalogController::class, 'activate'])->name('analyses.activate')->middleware('can:analysis_catalog.activate');
        Route::post('/analyses/{site}/{analysis}/deactivate', [SuperAdminAnalysisCatalogController::class, 'deactivate'])->name('analyses.deactivate')->middleware('can:analysis_catalog.deactivate');

        // Caisses nommées par site : même schéma UUID/idempotence/audit que les
        // adresses et mutuelles ; le site garde une seule caisse ouverte à la
        // fois (cash_sessions.active_key), inchangé par ce référentiel.
        Route::get('/cash-registers', [SuperAdminCashRegisterController::class, 'index'])->name('cash-registers.index')->middleware('can:cash_registers.view');
        Route::post('/cash-registers', [SuperAdminCashRegisterController::class, 'store'])->name('cash-registers.store')->middleware('can:cash_registers.create');
        Route::get('/cash-registers/{site}/{cashRegister}', [SuperAdminCashRegisterController::class, 'show'])->name('cash-registers.show')->middleware('can:cash_registers.view');
        Route::get('/cash-registers/{site}/{cashRegister}/export', [SuperAdminCashRegisterController::class, 'export'])->name('cash-registers.export')->middleware('can:cash_registers.export');
        Route::put('/cash-registers/{site}/{cashRegister}', [SuperAdminCashRegisterController::class, 'update'])->name('cash-registers.update')->middleware('can:cash_registers.update');
        Route::post('/cash-registers/{site}/{cashRegister}/session/lock', [SuperAdminCashRegisterController::class, 'lock'])->name('cash-registers.session.lock')->middleware('can:cash_registers.lock');
        Route::post('/cash-registers/{site}/{cashRegister}/session/unlock', [SuperAdminCashRegisterController::class, 'unlock'])->name('cash-registers.session.unlock')->middleware('can:cash_registers.unlock');
        Route::post('/cash-registers/{site}/{cashRegister}/session/close', [SuperAdminCashRegisterController::class, 'close'])->name('cash-registers.session.close')->middleware('can:cash_registers.close');
        Route::put('/cash-registers/{site}/{cashRegister}/payment-methods', [SuperAdminCashRegisterController::class, 'updatePaymentMethods'])->name('cash-registers.payment-methods.update')->middleware('can:cash_registers.update');

        // Tenders accepted at each site's cash desk. Mobile money operators
        // differ from one town to the next, so the list stays per-site and is
        // reached only through that site's API (ADR-004/025/027).
        Route::get('/payment-methods', [SuperAdminPaymentMethodController::class, 'index'])->name('payment-methods.index')->middleware('can:payment_methods.view');
        Route::post('/payment-methods', [SuperAdminPaymentMethodController::class, 'store'])->name('payment-methods.store')->middleware('can:payment_methods.create');
        Route::put('/payment-methods/{site}/{paymentMethod}', [SuperAdminPaymentMethodController::class, 'update'])->name('payment-methods.update')->middleware('can:payment_methods.update');
        Route::post('/payment-methods/{site}/{paymentMethod}/activate', [SuperAdminPaymentMethodController::class, 'activate'])->name('payment-methods.activate')->middleware('can:payment_methods.activate');
        Route::post('/payment-methods/{site}/{paymentMethod}/deactivate', [SuperAdminPaymentMethodController::class, 'deactivate'])->name('payment-methods.deactivate')->middleware('can:payment_methods.deactivate');
        Route::post('/cash-registers/{site}/{cashRegister}/activate', [SuperAdminCashRegisterController::class, 'activate'])->name('cash-registers.activate')->middleware('can:cash_registers.activate');
        Route::post('/cash-registers/{site}/{cashRegister}/deactivate', [SuperAdminCashRegisterController::class, 'deactivate'])->name('cash-registers.deactivate')->middleware('can:cash_registers.deactivate');
        Route::delete('/cash-registers/{site}/{cashRegister}', [SuperAdminCashRegisterController::class, 'destroy'])->name('cash-registers.destroy')->middleware('can:cash_registers.archive');
        Route::post('/cash-registers/{site}/{cashRegister}/restore', [SuperAdminCashRegisterController::class, 'restore'])->name('cash-registers.restore')->middleware(['can:trash.restore', 'can:cash_registers.restore']);
        Route::get('/workspaces/tariffs', [SuperAdminCatalogController::class, 'index'])->name('tariffs.index')->middleware(['can:catalog.items.view', 'can:catalog.tariffs.view']);
        Route::get('/workspaces/tariffs/export', [SuperAdminCatalogController::class, 'export'])->name('tariffs.export')->middleware('can:catalog.tariffs.export');
        Route::get('/workspaces/tariffs/import-template', [SuperAdminCatalogController::class, 'template'])->name('tariffs.import-template')->middleware('can:catalog.tariffs.import');
        Route::post('/workspaces/tariffs/import', [SuperAdminCatalogController::class, 'import'])->name('tariffs.import')->middleware('can:catalog.tariffs.import');
        Route::post('/workspaces/tariffs/items', [SuperAdminCatalogController::class, 'store'])->name('tariffs.items.store')->middleware('can:catalog.items.create');
        Route::post('/workspaces/tariffs/items/bulk/archive', [SuperAdminCatalogController::class, 'bulkArchive'])->name('tariffs.items.bulk.archive')->middleware('can:catalog.items.delete');
        Route::post('/workspaces/tariffs/items/bulk/restore', [SuperAdminCatalogController::class, 'bulkRestore'])->name('tariffs.items.bulk.restore')->middleware(['can:trash.restore', 'can:catalog.items.restore']);
        Route::put('/workspaces/tariffs/items/{site}/{catalog}', [SuperAdminCatalogController::class, 'update'])->name('tariffs.items.update')->middleware('can:catalog.items.update');
        Route::delete('/workspaces/tariffs/items/{site}/{catalog}', [SuperAdminCatalogController::class, 'destroy'])->name('tariffs.items.destroy')->middleware('can:catalog.items.delete');
        Route::post('/workspaces/tariffs/items/{site}/{catalog}/restore', [SuperAdminCatalogController::class, 'restore'])->name('tariffs.items.restore')->middleware(['can:trash.restore', 'can:catalog.items.restore']);
        Route::post('/workspaces/tariffs/items/{site}/{catalog}/tariffs', [SuperAdminCatalogController::class, 'setTariff'])->name('tariffs.values.store');
        Route::post('/workspaces/tariffs/items/{site}/{catalog}/tariffs/archive', [SuperAdminCatalogController::class, 'archiveTariff'])->name('tariffs.values.archive')->middleware('can:catalog.tariffs.archive');
        Route::post('/workspaces/tariffs/mutual-organizations', [SuperAdminMutualOrganizationController::class, 'store'])->name('tariffs.mutual-organizations.store')->middleware('can:mutual_organizations.create');
        Route::get('/workspaces/tariffs/mutual-organizations/export', [SuperAdminMutualOrganizationController::class, 'export'])->name('tariffs.mutual-organizations.export')->middleware('can:mutual_organizations.export');
        Route::get('/workspaces/tariffs/mutual-organizations/import-template', [SuperAdminMutualOrganizationController::class, 'template'])->name('tariffs.mutual-organizations.import-template')->middleware('can:mutual_organizations.import');
        Route::post('/workspaces/tariffs/mutual-organizations/import', [SuperAdminMutualOrganizationController::class, 'import'])->name('tariffs.mutual-organizations.import')->middleware('can:mutual_organizations.import');
        Route::post('/workspaces/tariffs/mutual-organizations/bulk/archive', [SuperAdminMutualOrganizationController::class, 'bulkArchive'])->name('tariffs.mutual-organizations.bulk.archive')->middleware('can:mutual_organizations.archive');
        Route::post('/workspaces/tariffs/mutual-organizations/bulk/restore', [SuperAdminMutualOrganizationController::class, 'bulkRestore'])->name('tariffs.mutual-organizations.bulk.restore')->middleware(['can:trash.restore', 'can:mutual_organizations.restore']);
        Route::put('/workspaces/tariffs/mutual-organizations/{site}/{organization}', [SuperAdminMutualOrganizationController::class, 'update'])->name('tariffs.mutual-organizations.update')->middleware('can:mutual_organizations.update');
        Route::delete('/workspaces/tariffs/mutual-organizations/{site}/{organization}', [SuperAdminMutualOrganizationController::class, 'destroy'])->name('tariffs.mutual-organizations.destroy')->middleware('can:mutual_organizations.archive');
        Route::post('/workspaces/tariffs/mutual-organizations/{site}/{organization}/restore', [SuperAdminMutualOrganizationController::class, 'restore'])->name('tariffs.mutual-organizations.restore')->middleware(['can:trash.restore', 'can:mutual_organizations.restore']);

        // Canevas de documents administratifs (ADR-070) : composés ici,
        // poussés site par site via l'API distante. L'ancien upload local
        // contract_templates.* (ADR-069) est retiré par l'ADR-071.
        Route::get('/workspaces/document-templates', [SuperAdminDocumentTemplateController::class, 'index'])->name('document-templates.index')->middleware('can:document_templates.view');
        Route::get('/workspaces/document-templates/{site}/create', [SuperAdminDocumentTemplateController::class, 'create'])->name('document-templates.create')->middleware('can:document_templates.create');
        Route::get('/workspaces/document-templates/{site}/{documentTemplate}/edit', [SuperAdminDocumentTemplateController::class, 'edit'])->name('document-templates.edit')->middleware('can:document_templates.update');
        Route::post('/workspaces/document-templates', [SuperAdminDocumentTemplateController::class, 'store'])->name('document-templates.store')->middleware('can:document_templates.create');
        Route::put('/workspaces/document-templates/{site}/{documentTemplate}', [SuperAdminDocumentTemplateController::class, 'update'])->name('document-templates.update')->middleware('can:document_templates.update');
        Route::delete('/workspaces/document-templates/{site}/{documentTemplate}', [SuperAdminDocumentTemplateController::class, 'destroy'])->name('document-templates.destroy')->middleware('can:document_templates.archive');
        Route::post('/workspaces/document-templates/{site}/{documentTemplate}/restore', [SuperAdminDocumentTemplateController::class, 'restore'])->name('document-templates.restore')->middleware(['can:trash.restore', 'can:document_templates.restore']);
        Route::post('/workspaces/document-templates/{site}/{documentTemplate}/duplicate', [SuperAdminDocumentTemplateController::class, 'duplicate'])->name('document-templates.duplicate')->middleware('can:document_templates.duplicate');
        Route::get('/workspaces/document-templates/{site}/{documentTemplate}/history', [SuperAdminDocumentTemplateController::class, 'history'])->name('document-templates.history')->middleware('can:document_templates.view');
        Route::post('/workspaces/document-templates/{site}/{documentTemplate}/revert', [SuperAdminDocumentTemplateController::class, 'revert'])->name('document-templates.revert')->middleware('can:document_templates.update');
        Route::post('/workspaces/document-templates/{site}/{documentTemplate}/activate', [SuperAdminDocumentTemplateController::class, 'activate'])->name('document-templates.activate')->middleware('can:document_templates.update');
        Route::post('/workspaces/document-templates/{site}/{documentTemplate}/deactivate', [SuperAdminDocumentTemplateController::class, 'deactivate'])->name('document-templates.deactivate')->middleware('can:document_templates.update');

        // Comptes utilisateurs, propres à chaque site — jamais gérés
        // directement en base, toujours via son API (ADR-025/027). Un compte
        // SUPER_ADMIN reste exclu par la même API distante
        // (UserAdministrationGuard côté site).
        //
        // Écran distinct de « Rôles & permissions » (ADR-100) : créer un
        // compte touche une personne, modifier un socle touche tous ceux qui
        // exercent le métier. L'ancienne URL /workspaces/roles servait les
        // deux ; elle reste valide et mène désormais aux rôles.
        Route::get('/workspaces/users', [SuperAdminUserController::class, 'index'])->name('workspaces.users')->middleware(['can:users.view', 'can:roles.view']);
        Route::post('/workspaces/users', [SuperAdminUserController::class, 'store'])->name('workspaces.users.store')->middleware(['can:users.create', 'can:roles.assign']);
        Route::post('/workspaces/users/bulk/deactivate', [SuperAdminUserController::class, 'bulkDeactivate'])->name('workspaces.users.bulk.deactivate')->middleware('can:users.deactivate');
        Route::post('/workspaces/users/bulk/force-delete', [SuperAdminUserController::class, 'bulkForceDelete'])->name('workspaces.users.bulk.force_delete')->middleware('can:users.force_delete');
        Route::put('/workspaces/users/{site}/{user}', [SuperAdminUserController::class, 'update'])->name('workspaces.users.update')->middleware(['can:users.update', 'can:roles.assign']);
        Route::post('/workspaces/users/{site}/{user}/activate', [SuperAdminUserController::class, 'activate'])->name('workspaces.users.activate')->middleware('can:users.activate');
        Route::post('/workspaces/users/{site}/{user}/deactivate', [SuperAdminUserController::class, 'deactivate'])->name('workspaces.users.deactivate')->middleware('can:users.deactivate');
        Route::delete('/workspaces/users/{site}/{user}', [SuperAdminUserController::class, 'forceDelete'])->name('workspaces.users.force_delete')->middleware('can:users.force_delete');

        // Le référentiel des rôles, leur socle, et les exceptions accordées
        // compte par compte (ADR-064, ADR-100).
        Route::get('/workspaces/roles', [SuperAdminRoleController::class, 'index'])->name('workspaces.roles')->middleware(['can:roles.view', 'can:permissions.view']);
        Route::post('/workspaces/roles', [SuperAdminRoleController::class, 'store'])->name('workspaces.roles.store')->middleware('can:roles.create');
        Route::put('/workspaces/roles/{site}/{role}', [SuperAdminRoleController::class, 'update'])->name('workspaces.roles.update')->middleware('can:roles.update');
        Route::delete('/workspaces/roles/{site}/{role}', [SuperAdminRoleController::class, 'archive'])->name('workspaces.roles.archive')->middleware('can:roles.archive');
        Route::post('/workspaces/roles/{site}/{role}/restore', [SuperAdminRoleController::class, 'restore'])->name('workspaces.roles.restore')->middleware('can:roles.restore');
        // Socle d'un RÔLE — tous ses comptes à la fois, additif aux
        // exceptions individuelles ci-dessous, qu'il ne touche jamais (ADR-064).
        Route::put('/workspaces/roles/{site}/permissions/{role}', [SuperAdminRoleController::class, 'updatePermissions'])->name('workspaces.roles.permissions.update')->middleware('can:users.manage');
        // Exceptions individuelles d'un compte : DENY prioritaire, puis
        // ALLOW, puis le socle du rôle (ADR-022, ADR-033).
        Route::put('/workspaces/roles/{site}/accounts/{user}/permissions', [SuperAdminRoleController::class, 'updateAccountPermissions'])->name('workspaces.roles.accounts.permissions.update')->middleware('can:permissions.assign');
        // Le catalogue des permissions du site (ADR-101).
        Route::post('/workspaces/roles/permissions', [SuperAdminRoleController::class, 'storePermission'])->name('workspaces.permissions.store')->middleware('can:permissions.create');
        Route::put('/workspaces/roles/{site}/catalog/{permission}', [SuperAdminRoleController::class, 'updatePermission'])->name('workspaces.permissions.update')->middleware('can:permissions.update');
        Route::delete('/workspaces/roles/{site}/catalog/{permission}', [SuperAdminRoleController::class, 'destroyPermission'])->name('workspaces.permissions.destroy')->middleware('can:permissions.delete');

        Route::get('/workspaces/hr', SuperAdminHumanResourcesController::class)->name('workspaces.hr')->middleware('can:employees.view');

        Route::get('/workspaces/{workspace}', [SuperAdminController::class, 'workspace'])->name('workspaces.show');
    });

// Patients/Episodes are per-site clinical data (ADR-004: admin.rivo.mg never
// reaches a site's own data directly, only via API) — clinic-only, unlike
// auth which the gateway/admin deployments also use.
Route::middleware(['site.type:clinic', 'auth', 'account.active', 'account.deployment'])->group(function () {
    // Corbeille locale, propre à ce site — distincte de la Corbeille
    // multi-sites du portail Super Admin (ADR-061). Restaurer reste réservé
    // par défaut au SUPER_ADMIN via trash.restore + la permission de la
    // catégorie (TrashController@index recalcule can_restore par ligne).
    Route::get('/trash', [TrashController::class, 'index'])->name('trash.index')->middleware('can:trash.view');
    Route::post('/trash/{category}/{uuid}/restore', [TrashController::class, 'restore'])->name('trash.restore')->middleware('can:trash.restore');

    Route::get('/administration', AdministrationController::class)->name('administration.index')->middleware('can:employees.view');
    Route::prefix('administration')->name('administration.')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index')->middleware('can:employees.view');
        Route::get('/employees/export', [EmployeeController::class, 'export'])->name('employees.export')->middleware('can:employees.export');
        Route::get('/employees/import', [EmployeeController::class, 'importPage'])->name('employees.import-page')->middleware('can:employees.import');
        Route::get('/employees/import-template', [EmployeeController::class, 'importTemplate'])->name('employees.import-template')->middleware('can:employees.import');
        Route::post('/employees/import', [EmployeeController::class, 'import'])->name('employees.import')->middleware('can:employees.import');
        Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create')->middleware('can:employees.create');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store')->middleware('can:employees.create');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show')->middleware('can:employees.view')->withTrashed();
        Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->middleware('can:employees.update');
        Route::get('/employees/{employee}/print', [EmployeeController::class, 'print'])->name('employees.print')->middleware('can:employees.print')->withTrashed();
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update')->middleware('can:employees.update');
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy')->middleware('can:employees.delete');
        Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])->name('employees.restore')->middleware('can:employees.restore')->withTrashed();

        Route::get('/contracts', [EmploymentContractController::class, 'index'])->name('contracts.index')->middleware('can:contracts.view');
        Route::get('/contracts/export', [EmploymentContractController::class, 'export'])->name('contracts.export')->middleware('can:contracts.export');
        Route::get('/contracts/create', [EmploymentContractController::class, 'create'])->name('contracts.create')->middleware('can:contracts.create');
        Route::post('/contracts', [EmploymentContractController::class, 'store'])->name('contracts.store')->middleware('can:contracts.create');
        Route::get('/contracts/{contract}/edit', [EmploymentContractController::class, 'edit'])->name('contracts.edit')->middleware('can:contracts.update');
        Route::get('/contracts/{contract}/print', [EmploymentContractController::class, 'print'])->name('contracts.print')->middleware('can:contracts.print')->withTrashed();
        Route::put('/contracts/{contract}', [EmploymentContractController::class, 'update'])->name('contracts.update')->middleware('can:contracts.update');
        Route::delete('/contracts/{contract}', [EmploymentContractController::class, 'destroy'])->name('contracts.destroy')->middleware('can:contracts.archive');
        Route::post('/contracts/{contract}/restore', [EmploymentContractController::class, 'restore'])->name('contracts.restore')->middleware('can:contracts.restore')->withTrashed();

        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index')->middleware('can:attendance.view');
        Route::get('/attendance/export', [AttendanceController::class, 'export'])->name('attendance.export')->middleware('can:attendance.export');
        Route::get('/attendance/print', [AttendanceController::class, 'print'])->name('attendance.print')->middleware('can:attendance.print');
        Route::get('/attendance/create', [AttendanceController::class, 'create'])->name('attendance.create')->middleware('can:attendance.create');
        Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store')->middleware('can:attendance.create');
        Route::get('/attendance/{attendance}/edit', [AttendanceController::class, 'edit'])->name('attendance.edit')->middleware('can:attendance.update');
        Route::put('/attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update')->middleware('can:attendance.update');

        Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index')->middleware('can:leave.view');
        Route::get('/leave/create', [LeaveController::class, 'create'])->name('leave.create')->middleware('can:leave.create');
        Route::post('/leave/preview', [LeaveController::class, 'preview'])->name('leave.preview')->middleware('can:leave.create');
        Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store')->middleware('can:leave.create');
        Route::post('/leave/{leave}/approve', [LeaveController::class, 'approve'])->name('leave.approve')->middleware('can:leave.approve');
        Route::post('/leave/{leave}/reject', [LeaveController::class, 'reject'])->name('leave.reject')->middleware('can:leave.reject');
        Route::post('/leave/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel')->middleware('can:leave.cancel');
        Route::get('/leave/{leave}/print', [LeaveController::class, 'print'])->name('leave.print')->middleware('can:leave.print');

        // Génération de documents depuis les canevas poussés par le Super
        // Admin (ADR-070) — lecture seule du référentiel document_templates,
        // aucune création/modification de canevas depuis ce module.
        // "generated-documents" (pas "documents"): /administration/documents
        // appartient déjà à HrDocumentController (pièces jointes uploadées).
        Route::get('/generated-documents', [GeneratedDocumentController::class, 'index'])->name('generated-documents.index')->middleware('can:generated_documents.view');
        Route::get('/generated-documents/create', [GeneratedDocumentController::class, 'create'])->name('generated-documents.create')->middleware('can:generated_documents.create');
        Route::post('/generated-documents/preview', [GeneratedDocumentController::class, 'preview'])->name('generated-documents.preview')->middleware('can:generated_documents.create');
        Route::post('/generated-documents', [GeneratedDocumentController::class, 'store'])->name('generated-documents.store')->middleware('can:generated_documents.create');
        Route::get('/generated-documents/{generatedDocument}/print', [GeneratedDocumentController::class, 'print'])->name('generated-documents.print')->middleware('can:generated_documents.print');

        Route::get('/planning', [PlanningController::class, 'index'])->name('planning.index')->middleware('can:planning.view');
        Route::get('/planning/export', [PlanningController::class, 'export'])->name('planning.export')->middleware('can:planning.export');
        Route::get('/planning/print', [PlanningController::class, 'print'])->name('planning.print')->middleware('can:planning.print');
        Route::get('/planning/create', [PlanningController::class, 'create'])->name('planning.create')->middleware('can:planning.create');
        Route::post('/planning', [PlanningController::class, 'store'])->name('planning.store')->middleware('can:planning.create');
        Route::get('/planning/{planning}/edit', [PlanningController::class, 'edit'])->name('planning.edit')->middleware('can:planning.update');
        Route::put('/planning/{planning}', [PlanningController::class, 'update'])->name('planning.update')->middleware('can:planning.update');

        Route::get('/reports', [HrReportController::class, 'index'])->name('reports.index')->middleware('can:hr_reports.view');
        Route::get('/reports/export', [HrReportController::class, 'export'])->name('reports.export')->middleware('can:hr_reports.export');
        Route::get('/reports/print', [HrReportController::class, 'print'])->name('reports.print')->middleware('can:hr_reports.print');

        Route::get('/settings', [HrReferenceController::class, 'index'])->name('settings.index')->middleware('can:hr_settings.view');
        Route::post('/settings', [HrReferenceController::class, 'store'])->name('settings.store')->middleware('can:hr_settings.create');
        Route::put('/settings/{reference}', [HrReferenceController::class, 'update'])->name('settings.update')->middleware('can:hr_settings.update');
        Route::delete('/settings/{reference}', [HrReferenceController::class, 'destroy'])->name('settings.destroy')->middleware('can:hr_settings.archive');
        Route::post('/settings/{reference}/restore', [HrReferenceController::class, 'restore'])->name('settings.restore')->middleware('can:hr_settings.restore')->withTrashed();

        Route::post('/documents', [HrDocumentController::class, 'store'])->name('documents.store')->middleware('can:hr_documents.create');
        Route::get('/documents/{document}', [HrDocumentController::class, 'show'])->name('documents.show')->middleware('can:hr_documents.view')->withTrashed();
        Route::get('/documents/{document}/download', [HrDocumentController::class, 'download'])->name('documents.download')->middleware('can:hr_documents.view')->withTrashed();
        Route::delete('/documents/{document}', [HrDocumentController::class, 'destroy'])->name('documents.destroy')->middleware('can:hr_documents.archive');
        Route::post('/documents/{document}/restore', [HrDocumentController::class, 'restore'])->name('documents.restore')->middleware('can:hr_documents.restore')->withTrashed();
    });
    Route::get('/administration/staff-block-credits', [StaffBlockCreditController::class, 'index'])
        ->name('administration.staff-block-credits.index')
        ->middleware('can:staff_block_credits.view');
    Route::post('/administration/staff-block-credits/{employee}', [StaffBlockCreditController::class, 'store'])
        ->name('administration.staff-block-credits.store')
        ->middleware('can:staff_block_credits.allocate');
    Route::get('/logistics', LogisticsController::class)->name('logistics.index')->middleware('can:logistics.view');
    // ADR-098 — Pharmacie : une vraie page par tâche, le menu latéral comme
    // seule navigation. Chaque page porte la permission de l'écran qu'elle ouvre.
    Route::get('/pharmacy', PharmacyDashboardController::class)->name('pharmacy.index')->middleware('can:pharmacy.view');

    Route::get('/pharmacy/stock', [PharmacyStockController::class, 'index'])
        ->name('pharmacy.stock.index')->middleware('can:view-pharmacy-catalog');
    Route::get('/pharmacy/stock/entries/create', [PharmacyStockController::class, 'createEntry'])
        ->name('pharmacy.stock.entries.create')->middleware('can:stock.entry');
    Route::post('/pharmacy/stock/entries', [PharmacyStockController::class, 'storeEntry'])
        ->name('pharmacy.stock.entries.store')->middleware('can:stock.entry');
    // ADR-098 — a whole delivery, checked line by line, recorded at once.
    Route::post('/pharmacy/stock/entries/batch', [PharmacyStockController::class, 'storeEntries'])
        ->name('pharmacy.stock.entries.batch')->middleware('can:stock.entry');
    Route::get('/pharmacy/stock/inventory', [PharmacyStockController::class, 'inventory'])
        ->name('pharmacy.stock.inventory')->middleware('can:stock.adjust');
    Route::post('/pharmacy/stock/inventory', [PharmacyStockController::class, 'storeInventory'])
        ->name('pharmacy.stock.inventory.store')->middleware('can:stock.adjust');
    Route::get('/pharmacy/stock/adjustments/create', [PharmacyStockController::class, 'createAdjustment'])
        ->name('pharmacy.stock.adjustments.create')->middleware('can:stock.adjust');
    Route::post('/pharmacy/stock/adjustments', [PharmacyStockController::class, 'storeAdjustment'])
        ->name('pharmacy.stock.adjustments.store')->middleware('can:stock.adjust');
    Route::get('/pharmacy/stock/{medicine}', [PharmacyStockController::class, 'show'])
        ->name('pharmacy.stock.show')->middleware('can:stock.view');

    Route::get('/pharmacy/counter-sales/create', [PharmacyCounterSaleController::class, 'create'])
        ->name('pharmacy.counter-sales.create')->middleware('can:pharmacy.counter_sales.create');
    Route::post('/pharmacy/counter-sales', [PharmacyCounterSaleController::class, 'store'])
        ->name('pharmacy.counter-sales.store')->middleware('can:pharmacy.counter_sales.create');

    Route::get('/pharmacy/dispenses', [PharmacyDispenseController::class, 'index'])
        ->name('pharmacy.dispenses.index')->middleware('can:prescriptions.view');
    Route::post('/pharmacy/dispenses/{dispense}/invoice', [PharmacyDispenseController::class, 'prepareInvoice'])
        ->name('pharmacy.dispenses.invoice.store')->middleware('can:pharmacy.dispense.prepare_invoice');
    Route::get('/pharmacy/dispenses/{dispense}/ticket', [PharmacyDispenseController::class, 'ticket'])
        ->name('pharmacy.dispenses.ticket.show')->middleware('can:pharmacy.dispense.print');
    Route::post('/pharmacy/dispenses/{dispense}/deliveries', [PharmacyDispenseController::class, 'deliver'])
        ->name('pharmacy.dispenses.deliveries.store')->middleware('can:pharmacy.dispense');

    Route::get('/pharmacy/care-consumables', [PharmacyCareConsumableController::class, 'index'])
        ->name('pharmacy.care-consumables.index')->middleware('can:care_consumables.view');
    Route::post('/pharmacy/care-consumables/{careConsumableRequest}/serve', [PharmacyCareConsumableController::class, 'serve'])
        ->name('pharmacy.care-consumables.serve')->middleware('can:care_consumables.serve');

    // ADR-098 — merged into « Médicaments & stock »; the address still works.
    Route::get('/pharmacy/medicines', [PharmacyMedicineController::class, 'index'])
        ->name('pharmacy.medicines.index')->middleware('can:view-pharmacy-catalog');
    Route::get('/pharmacy/purchases', PharmacyPurchasesController::class)
        ->name('pharmacy.purchases.index')->middleware('can:view-pharmacy-purchases');
    Route::get('/pharmacy/medicines/create', [PharmacyMedicineController::class, 'create'])
        ->name('pharmacy.medicines.create')->middleware('can:medicines.create');
    Route::post('/pharmacy/setup/categories', [PharmacyMedicineController::class, 'storeCategory'])
        ->name('pharmacy.setup.categories.store')->middleware('can:medicine_categories.create');
    // ADR-098 — correcting the catalog: a medicine is deactivated, never deleted.
    Route::get('/pharmacy/medicines/{medicine}/edit', [PharmacyMedicineController::class, 'edit'])
        ->name('pharmacy.medicines.edit')->middleware('can:medicines.update');
    Route::put('/pharmacy/medicines/{medicine}', [PharmacyMedicineController::class, 'update'])
        ->name('pharmacy.medicines.update')->middleware('can:medicines.update');
    Route::post('/pharmacy/medicines/{medicine}/deactivate', [PharmacyMedicineController::class, 'deactivate'])
        ->name('pharmacy.medicines.deactivate')->middleware('can:medicines.delete');
    Route::post('/pharmacy/medicines/{medicine}/reactivate', [PharmacyMedicineController::class, 'reactivate'])
        ->name('pharmacy.medicines.reactivate')->middleware('can:medicines.restore');
    Route::put('/pharmacy/setup/categories/{category}', [PharmacyMedicineController::class, 'updateCategory'])
        ->name('pharmacy.setup.categories.update')->middleware('can:medicine_categories.update');
    Route::delete('/pharmacy/setup/categories/{category}', [PharmacyMedicineController::class, 'archiveCategory'])
        ->name('pharmacy.setup.categories.destroy')->middleware('can:medicine_categories.delete');
    Route::post('/pharmacy/setup/categories/{category}/restore', [PharmacyMedicineController::class, 'restoreCategory'])
        ->name('pharmacy.setup.categories.restore')->middleware('can:medicine_categories.restore')->withTrashed();
    Route::post('/pharmacy/setup/medicines', [PharmacyMedicineController::class, 'store'])
        ->name('pharmacy.setup.medicines.store')->middleware('can:medicines.create');
    Route::get('/pharmacy/setup/medicines/import-template', [PharmacyMedicineController::class, 'template'])
        ->name('pharmacy.setup.medicines.import-template')->middleware('can:medicines.import');
    Route::post('/pharmacy/setup/medicines/import', [PharmacyMedicineController::class, 'import'])
        ->name('pharmacy.setup.medicines.import')->middleware('can:medicines.import');

    // ADR-097/098 — Fournisseurs : un dossier par fournisseur (catalogues,
    // commandes, factures, prix), ouvert à la clinique en consultation.
    Route::get('/pharmacy/suppliers/catalog-template', SupplierCatalogTemplateController::class)
        ->name('pharmacy.suppliers.catalog-template')->middleware('can:supplier_catalogs.view');
    Route::get('/pharmacy/suppliers', [SupplierController::class, 'index'])
        ->name('pharmacy.suppliers.index')->middleware('can:medicine_suppliers.view');
    Route::post('/pharmacy/setup/suppliers', [SupplierController::class, 'store'])
        ->name('pharmacy.setup.suppliers.store')->middleware('can:medicine_suppliers.create');
    Route::get('/pharmacy/suppliers/{supplier}', [SupplierController::class, 'show'])
        ->name('pharmacy.suppliers.show')->middleware('can:medicine_suppliers.view');
    Route::get('/pharmacy/suppliers/{supplier}/catalogs', [SupplierController::class, 'catalogs'])
        ->name('pharmacy.suppliers.catalogs.index')->middleware('can:view-supplier-catalogs');
    Route::get('/pharmacy/suppliers/{supplier}/catalogs/{catalog}/items', [SupplierController::class, 'catalogItems'])
        ->name('pharmacy.suppliers.catalogs.items')->middleware('can:view-supplier-catalogs')->withTrashed();
    Route::get('/pharmacy/suppliers/{supplier}/products', [SupplierController::class, 'products'])
        ->name('pharmacy.suppliers.products')->middleware('can:view-supplier-offers');
    Route::get('/pharmacy/suppliers/{supplier}/catalogs/{catalog}/import', [SupplierCatalogController::class, 'preview'])
        ->name('pharmacy.suppliers.catalogs.import.preview')->middleware('can:supplier_catalogs.create');

    Route::post('/pharmacy/suppliers/{supplier}/catalogs', [SupplierCatalogController::class, 'store'])
        ->name('pharmacy.suppliers.catalogs.store')->middleware('can:supplier_catalogs.create');
    Route::get('/pharmacy/suppliers/{supplier}/catalogs/{catalog}', [SupplierCatalogController::class, 'show'])
        ->name('pharmacy.suppliers.catalogs.show')->middleware('can:view-supplier-catalogs')->withTrashed();
    Route::get('/pharmacy/suppliers/{supplier}/catalogs/{catalog}/download', [SupplierCatalogController::class, 'download'])
        ->name('pharmacy.suppliers.catalogs.download')->middleware('can:view-supplier-catalogs')->withTrashed();
    Route::patch('/pharmacy/suppliers/{supplier}/catalogs/{catalog}', [SupplierCatalogController::class, 'update'])
        ->name('pharmacy.suppliers.catalogs.update')->middleware('can:supplier_catalogs.update');
    Route::post('/pharmacy/suppliers/{supplier}/catalogs/{catalog}/activate', [SupplierCatalogController::class, 'activate'])
        ->name('pharmacy.suppliers.catalogs.activate')->middleware('can:supplier_catalogs.update');
    Route::post('/pharmacy/suppliers/{supplier}/catalogs/{catalog}/import', [SupplierCatalogController::class, 'import'])
        ->name('pharmacy.suppliers.catalogs.import')->middleware('can:supplier_catalogs.create');
    Route::delete('/pharmacy/suppliers/{supplier}/catalogs/{catalog}', [SupplierCatalogController::class, 'destroy'])
        ->name('pharmacy.suppliers.catalogs.destroy')->middleware('can:supplier_catalogs.delete');
    Route::post('/pharmacy/suppliers/{supplier}/catalogs/{catalog}/restore', [SupplierCatalogController::class, 'restore'])
        ->name('pharmacy.suppliers.catalogs.restore')->middleware('can:supplier_catalogs.restore')->withTrashed();
    Route::post('/pharmacy/suppliers/{supplier}/catalog-items/{catalogItem}/link', [SupplierCatalogController::class, 'linkItem'])
        ->name('pharmacy.suppliers.catalog-items.link')->middleware('can:set-medicine-supplier-offer');

    Route::post('/pharmacy/suppliers/{supplier}/offers', [MedicineSupplierOfferController::class, 'store'])
        ->name('pharmacy.suppliers.offers.store')->middleware('can:set-medicine-supplier-offer');

    Route::get('/pharmacy/purchase-orders', [PurchaseOrderController::class, 'index'])
        ->name('pharmacy.purchase-orders.index')->middleware('can:view-supplier-orders');
    Route::get('/pharmacy/purchase-orders/create', [PurchaseOrderController::class, 'create'])
        ->name('pharmacy.purchase-orders.create')->middleware('can:purchase_orders.create');
    Route::post('/pharmacy/suppliers/{supplier}/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->name('pharmacy.suppliers.purchase-orders.store')->middleware('can:purchase_orders.create');
    Route::get('/pharmacy/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
        ->name('pharmacy.purchase-orders.show')->middleware('can:view-supplier-orders');
    Route::match(['put', 'patch'], '/pharmacy/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
        ->name('pharmacy.purchase-orders.update')->middleware('can:purchase_orders.update');
    Route::get('/pharmacy/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])
        ->name('pharmacy.purchase-orders.edit')->middleware('can:purchase_orders.update');
    Route::post('/pharmacy/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])
        ->name('pharmacy.purchase-orders.submit')->middleware('can:purchase_orders.submit');
    Route::post('/pharmacy/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->name('pharmacy.purchase-orders.cancel')->middleware('can:purchase_orders.cancel');

    Route::get('/pharmacy/purchase-orders/{purchaseOrder}/receive', [GoodsReceiptController::class, 'create'])
        ->name('pharmacy.purchase-orders.receive')->middleware('can:goods_receipts.create');
    Route::post('/pharmacy/purchase-orders/{purchaseOrder}/receipts', [GoodsReceiptController::class, 'store'])
        ->name('pharmacy.purchase-orders.receipts.store')->middleware('can:goods_receipts.create');
    Route::get('/pharmacy/receipts', [GoodsReceiptController::class, 'index'])
        ->name('pharmacy.receipts.index')->middleware('can:goods_receipts.view');
    Route::get('/pharmacy/receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
        ->name('pharmacy.receipts.show')->middleware('can:goods_receipts.view');

    Route::get('/pharmacy/supplier-invoices', [SupplierInvoiceController::class, 'index'])
        ->name('pharmacy.supplier-invoices.index')->middleware('can:view-supplier-invoices');
    Route::get('/pharmacy/supplier-invoices/create', [SupplierInvoiceController::class, 'create'])
        ->name('pharmacy.supplier-invoices.create')->middleware('can:supplier_invoices.create');
    Route::post('/pharmacy/suppliers/{supplier}/invoices', [SupplierInvoiceController::class, 'store'])
        ->name('pharmacy.suppliers.invoices.store')->middleware('can:supplier_invoices.create');
    Route::get('/pharmacy/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'show'])
        ->name('pharmacy.supplier-invoices.show')->middleware('can:view-supplier-invoices');
    Route::get('/pharmacy/supplier-invoices/{supplierInvoice}/attachment', [SupplierInvoiceController::class, 'attachment'])
        ->name('pharmacy.supplier-invoices.attachment')->middleware('can:view-supplier-invoices')->withTrashed();
    Route::get('/pharmacy/supplier-invoices/{supplierInvoice}/edit', [SupplierInvoiceController::class, 'edit'])
        ->name('pharmacy.supplier-invoices.edit')->middleware('can:supplier_invoices.update');
    // POST: a corrected invoice may carry a new document.
    Route::post('/pharmacy/supplier-invoices/{supplierInvoice}/update', [SupplierInvoiceController::class, 'update'])
        ->name('pharmacy.supplier-invoices.update')->middleware('can:supplier_invoices.update');
    Route::delete('/pharmacy/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'destroy'])
        ->name('pharmacy.supplier-invoices.destroy')->middleware('can:supplier_invoices.delete');
    Route::post('/pharmacy/supplier-invoices/{supplierInvoice}/restore', [SupplierInvoiceController::class, 'restore'])
        ->name('pharmacy.supplier-invoices.restore')->middleware('can:supplier_invoices.restore')->withTrashed();

    // Administration locale des comptes de ce site. Les comptes sont
    // désactivés, jamais supprimés, afin de préserver leurs traces d'audit.
    Route::get('/administration/users', [AdministrationUserController::class, 'index'])->name('administration.users.index')->middleware('can:users.view');
    Route::post('/administration/users', [AdministrationUserController::class, 'store'])->name('administration.users.store')->middleware('can:users.create');
    Route::put('/administration/users/{user}', [AdministrationUserController::class, 'update'])->name('administration.users.update')->middleware('can:users.update');
    Route::post('/administration/users/{user}/deactivate', [AdministrationUserController::class, 'deactivate'])->name('administration.users.deactivate')->middleware('can:users.deactivate');
    Route::post('/administration/users/{user}/activate', [AdministrationUserController::class, 'activate'])->name('administration.users.activate')->middleware('can:users.activate');

    // ADR-024 — catalogue et tarifs propres au site. Le serveur central
    // appliquera ultérieurement ces opérations aux sites via leurs API,
    // jamais par accès direct aux bases locales.
    Route::get('/administration/catalog', [AdministrationCatalogController::class, 'index'])->name('administration.catalog.index')->middleware('can:catalog.items.view');
    Route::post('/administration/catalog', [AdministrationCatalogController::class, 'store'])->name('administration.catalog.store')->middleware('can:catalog.items.create');
    Route::put('/administration/catalog/{catalogItem}', [AdministrationCatalogController::class, 'update'])->name('administration.catalog.update')->middleware('can:catalog.items.update');
    // Le tarif exige create lorsqu'il n'existe pas encore, update sinon :
    // le FormRequest puis l'Action vérifient précisément ce cas atomique.
    Route::post('/administration/catalog/{catalogItem}/tariff', [AdministrationCatalogController::class, 'setTariff'])->name('administration.catalog.tariff.store');
    Route::post('/administration/catalog/{catalogItem}/tariff/archive', [AdministrationCatalogController::class, 'archiveTariff'])->name('administration.catalog.tariff.archive')->middleware('can:catalog.tariffs.archive');
    // ADR-072 — matériel habituel d'un acte de soins (suggestion de saisie).
    Route::put('/administration/catalog/{catalogItem}/care-consumables', [AdministrationCatalogController::class, 'syncCareConsumables'])->name('administration.catalog.care-consumables.update')->middleware('can:catalog.items.update');
    Route::delete('/administration/catalog/{catalogItem}', [AdministrationCatalogController::class, 'destroy'])->name('administration.catalog.destroy')->middleware('can:catalog.items.delete');
    Route::post('/administration/catalog/{catalogItem}/restore', [AdministrationCatalogController::class, 'restore'])->name('administration.catalog.restore')->middleware(['can:trash.restore', 'can:catalog.items.restore']);
    // Médicament ajouté manuellement par un médecin (ordonnance jamais
    // bloquée par une absence au référentiel) : jamais de stock ni de prix,
    // seulement une trace en attente pour qui détient catalog.items.create.
    Route::post('/administration/catalog/pending-medicines/{prescriptionLine}/review', [AdministrationCatalogController::class, 'reviewUnlistedMedicine'])->name('administration.catalog.pending-medicines.review')->middleware('can:catalog.items.create');

    Route::get('/administration/diagnostics', [DiagnosticCatalogController::class, 'index'])->name('administration.diagnostics.index')->middleware('can:diagnostic_catalog.view');
    Route::post('/administration/diagnostics', [DiagnosticCatalogController::class, 'store'])->name('administration.diagnostics.store')->middleware('can:diagnostic_catalog.manage');
    Route::put('/administration/diagnostics/{diagnosticCatalog}', [DiagnosticCatalogController::class, 'update'])->name('administration.diagnostics.update')->middleware('can:diagnostic_catalog.manage');
    Route::post('/administration/diagnostics/{diagnosticCatalog}/activate', [DiagnosticCatalogController::class, 'activate'])->name('administration.diagnostics.activate')->middleware('can:diagnostic_catalog.manage');
    Route::post('/administration/diagnostics/{diagnosticCatalog}/deactivate', [DiagnosticCatalogController::class, 'deactivate'])->name('administration.diagnostics.deactivate')->middleware('can:diagnostic_catalog.manage');

    Route::get('/administration/analyses', [AnalysisCatalogController::class, 'index'])->name('administration.analyses.index')->middleware('can:analysis_catalog.view');
    Route::get('/administration/analyses/export', [AnalysisCatalogController::class, 'export'])->name('administration.analyses.export')->middleware('can:analysis_catalog.export');
    Route::get('/administration/analyses/import-template', [AnalysisCatalogController::class, 'template'])->name('administration.analyses.import-template')->middleware('can:analysis_catalog.import');
    Route::post('/administration/analyses/import', [AnalysisCatalogController::class, 'import'])->name('administration.analyses.import')->middleware('can:analysis_catalog.import');
    Route::get('/administration/analyses/create', [AnalysisCatalogController::class, 'create'])->name('administration.analyses.create')->middleware('can:analysis_catalog.create');
    Route::post('/administration/analyses', [AnalysisCatalogController::class, 'store'])->name('administration.analyses.store')->middleware('can:analysis_catalog.create');
    Route::get('/administration/analyses/{analysisCatalog}/edit', [AnalysisCatalogController::class, 'edit'])->name('administration.analyses.edit')->middleware('can:analysis_catalog.update');
    Route::put('/administration/analyses/{analysisCatalog}', [AnalysisCatalogController::class, 'update'])->name('administration.analyses.update')->middleware('can:analysis_catalog.update');
    Route::post('/administration/analyses/{analysisCatalog}/activate', [AnalysisCatalogController::class, 'activate'])->name('administration.analyses.activate')->middleware('can:analysis_catalog.activate');
    Route::post('/administration/analyses/{analysisCatalog}/deactivate', [AnalysisCatalogController::class, 'deactivate'])->name('administration.analyses.deactivate')->middleware('can:analysis_catalog.deactivate');

    // Named cash registers (Caisse 1, Caisse 2…) are managed both locally
    // here and remotely from the Super Admin portal (mirroring
    // catalog/addresses/mutuelles) — only one session can be open at a time
    // regardless of how many registers exist (cash_sessions.active_key
    // singleton, unchanged by either front door).
    Route::get('/administration/cash-registers', [CashRegisterController::class, 'index'])->name('administration.cash-registers.index')->middleware('can:cash_registers.view');
    Route::post('/administration/cash-registers', [CashRegisterController::class, 'store'])->name('administration.cash-registers.store')->middleware('can:cash_registers.create');
    Route::put('/administration/cash-registers/{cashRegister}', [CashRegisterController::class, 'update'])->name('administration.cash-registers.update')->middleware('can:cash_registers.update');
    Route::post('/administration/cash-registers/{cashRegister}/activate', [CashRegisterController::class, 'activate'])->name('administration.cash-registers.activate')->middleware('can:cash_registers.activate');
    Route::post('/administration/cash-registers/{cashRegister}/deactivate', [CashRegisterController::class, 'deactivate'])->name('administration.cash-registers.deactivate')->middleware('can:cash_registers.deactivate');
    Route::delete('/administration/cash-registers/{cashRegister}', [CashRegisterController::class, 'destroy'])->name('administration.cash-registers.destroy')->middleware('can:cash_registers.archive');
    Route::post('/administration/cash-registers/{cashRegister}/restore', [CashRegisterController::class, 'restore'])->name('administration.cash-registers.restore')->middleware(['can:trash.restore', 'can:cash_registers.restore']);

    // Réception: one operational entry point, with isolated patient and
    // non-clinical visitor workflows. A visitor never creates an episode.
    Route::get('/reception', [ReceptionController::class, 'index'])->name('reception.index')->middleware('can:reception.view');
    Route::get('/reception/patients', [ReceptionController::class, 'patients'])->name('reception.patients.create')->middleware('can:episodes.create');
    Route::get('/reception/patients/search', [ReceptionController::class, 'searchPatients'])
        ->name('reception.patients.search')
        ->middleware('can:episodes.create');
    Route::post('/reception/estimates', ReceptionEstimateController::class)
        ->name('reception.estimates.store')
        ->middleware('can:episodes.create');
    // Legacy wizard URL kept as a safe redirect after ADR-030 moved service
    // selection to the newly created passage itself.
    Route::get('/reception/patients/prestations', fn () => redirect()->route('reception.patients.create'));
    Route::get('/reception/patients/{step}', [ReceptionController::class, 'patientStep'])
        ->name('reception.patients.step')
        ->whereIn('step', ReceptionPatientStep::values())
        ->middleware('can:episodes.create');
    Route::post('/reception/patients', [ReceptionController::class, 'storePatient'])->name('reception.patients.store')->middleware('can:episodes.create');
    Route::get('/reception/employees/patient-lookup', EmployeePatientLookupController::class)
        ->name('reception.employees.patient-lookup')
        ->middleware('can:employees.patient_lookup');
    Route::get('/reception/passages/{episode}/prise-en-charge', [ReceptionController::class, 'resumeJourney'])
        ->name('reception.passages.journey.show')
        ->middleware('can:episodes.update');
    Route::post('/reception/passages/{episode}/urgence', [EpisodeEmergencyController::class, 'fromReception'])
        ->name('reception.passages.emergency.store')
        ->middleware('can:episodes.mark_emergency');
    Route::get('/reception/passages/{episode}/prestations', [EpisodeServiceController::class, 'show'])
        ->name('reception.passages.services.show')
        ->middleware('can:episodes.update');
    Route::post('/reception/passages/{episode}/financial-context', [EpisodeFinancialContextController::class, 'store'])
        ->name('reception.passages.financial-context.store')
        ->middleware('can:episodes.update');
    Route::post('/reception/passages/{episode}/prestations', [EpisodeServiceController::class, 'store'])
        ->name('reception.passages.services.store')
        ->middleware('can:episodes.update');
    // CDC §33.3 — sortie administrative. Réception's own decision on the
    // passages Médecine has finished with: control the account (§33.2),
    // then close the passage as paid / debt / escape. Never a clinical
    // action, and never a payment: collecting stays at /cash (ADR-012).
    Route::get('/reception/sorties', [EpisodeSettlementController::class, 'index'])
        ->name('reception.settlements.index')
        ->middleware('can:episodes.settlement.view');
    Route::post('/reception/passages/{episode}/sortie-administrative', [EpisodeSettlementController::class, 'store'])
        ->name('reception.passages.administrative-exit.store')
        ->middleware('can:episodes.administrative_exit');
    Route::get('/reception/mutual-coverages/{coverage}/attachments/{attachment}', PatientMutualCoverageAttachmentController::class)
        ->name('reception.mutual-coverages.attachments.show')
        ->scopeBindings()
        ->middleware(['can:patients.view', 'can:patient_coverage_documents.view']);
    Route::get('/reception/visitors', [VisitorReceptionController::class, 'index'])->name('reception.visitors.index')->middleware('can:visitors.view');
    Route::post('/reception/visitors', [VisitorReceptionController::class, 'store'])->name('reception.visitors.store')->middleware('can:visitors.create');
    Route::get('/reception/visitors/{visitorVisit}/attachments/{attachment}', [VisitorReceptionController::class, 'professionalAttachment'])->name('reception.visitors.attachments.show')->middleware('can:visitors.view');
    Route::post('/reception/visitors/{visitorVisit}/close', [VisitorReceptionController::class, 'close'])->name('reception.visitors.close')->middleware('can:visitors.close');

    // Référentiel patients: administrative management, but no creation here.
    // Deletion is always audited Soft Delete through Patient::SoftDeletable.
    // L'en-tête : recherche rapide et points d'attention. Toutes deux
    // n'exposent que ce que le compte a déjà le droit de voir.
    Route::get('/recherche', GlobalSearchController::class)->name('search.global')->middleware('can:patients.view');
    Route::get('/points-attention', AttentionDigestController::class)->name('attention.digest');

    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index')->middleware('can:patients.view');
    Route::post('/patients/bulk-delete', [PatientController::class, 'bulkDestroy'])->name('patients.bulk-destroy')->middleware('can:patients.delete');
    Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])->name('patients.edit')->middleware('can:patients.update');
    Route::put('/patients/{patient}', [PatientController::class, 'update'])->name('patients.update')->middleware('can:patients.update');
    Route::post('/patients/{patient}/mutual-coverages/{mutualCoverage}/attachments', [PatientMutualCoverageAttachmentController::class, 'store'])
        ->name('patients.mutual-coverages.attachments.store')
        ->scopeBindings()
        ->middleware([
            'can:patients.update',
            'can:patient_coverages.view',
            'can:patient_coverage_documents.create',
        ]);
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy'])->name('patients.destroy')->middleware('can:patients.delete');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show')->middleware('can:patients.view');
    // Generic endpoint: an antecedent is a permanent Patient record, never a
    // Consultation field — any caller with the permission uses this one
    // route (Médecine included), never a module-specific duplicate.
    Route::post('/patients/{patient}/antecedents', [PatientController::class, 'storeAntecedent'])
        ->name('patients.antecedents.store')
        ->middleware('can:patients.medical_history.manage');

    // Vue d'ensemble en lecture seule d'un passage — agrège Soins, Médecine
    // et facturation déjà accessibles séparément par module ; aucune action
    // n'est réalisée ici, chaque section reste protégée par la permission du
    // module qui possède réellement la donnée.
    Route::get('/passages/{episode}', [EpisodeController::class, 'show'])->name('passages.show')->middleware('can:patients.view');

    // Facturation / caisse : une seule caisse fonctionnelle par site. Les
    // prestations peuvent être facturées ici, mais seul ce module encaisse.
    Route::get('/cash', [CashController::class, 'index'])->name('cash.index')->middleware('can:cash.view');
    Route::get('/cash/{cashRegister}', [CashController::class, 'show'])->name('cash.show')->middleware('can:cash.view');
    Route::post('/cash/open', [CashController::class, 'open'])->name('cash.open')->middleware('can:cash.open');
    Route::post('/cash/close', [CashController::class, 'close'])->name('cash.close')->middleware('can:cash.close');
    Route::post('/patients/{patient}/invoices', [BillingController::class, 'store'])->name('invoices.store')->middleware('can:billing.create');
    Route::get('/invoices/{invoice}', [BillingController::class, 'show'])->name('invoices.show')->middleware('can:billing.print');
    Route::post('/invoices/{invoice}/validate', [BillingController::class, 'validateInvoice'])->name('invoices.validate')->middleware('can:billing.validate');
    Route::post('/patients/{patient}/payments', [PaymentController::class, 'store'])->name('payments.store')->middleware('can:payments.create');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'storeInvoice'])->name('invoices.payments.store')->middleware('can:payments.create');
    Route::post('/payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel')->middleware('can:payments.cancel');
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show')->middleware('can:receipts.view');

    Route::post('/patients/{patient}/episodes', [EpisodeController::class, 'store'])->name('episodes.store')->middleware('can:episodes.create');

    // ADR-030 — operational queues are isolated by destination and opened
    // from the route configured on each selected designation. Unknown needs
    // start in Soins; an emergency receives both queues at admission.
    Route::get('/care', [CareController::class, 'index'])->name('care.index')->middleware('can:care.view');
    Route::get('/care/orientations/{episodeOrientation}', [CareController::class, 'show'])->name('care.orientations.show')->middleware('can:care.view');
    Route::put('/care/orientations/{episodeOrientation}/record', [CareController::class, 'saveRecord'])->name('care.orientations.record.update')->middleware('can:care.view');
    Route::put('/care/orientations/{episodeOrientation}/record-and-complete', [CareController::class, 'saveAndComplete'])->name('care.orientations.record-and-complete')->middleware('can:care.complete');
    Route::post('/care/orientations/{episodeOrientation}/accept', [CareController::class, 'accept'])->name('care.orientations.accept')->middleware('can:care.update');
    Route::post('/care/orientations/{episodeOrientation}/complete', [CareController::class, 'complete'])->name('care.orientations.complete')->middleware('can:care.complete');
    Route::post('/care/orientations/{episodeOrientation}/complete-and-orient', [CareController::class, 'completeAndOrient'])->name('care.orientations.complete-and-orient')->middleware('can:care.complete');
    // Autosaved typing on the worksheet: survives a reload, discarded only
    // by the nurse or by a real save.
    Route::put('/care/orientations/{episodeOrientation}/draft', [CareController::class, 'saveDraft'])->name('care.orientations.draft.update')->middleware('can:care.view');
    Route::delete('/care/orientations/{episodeOrientation}/draft', [CareController::class, 'discardDraft'])->name('care.orientations.draft.destroy')->middleware('can:care.view');
    // ADR-072 — declaring consumables happens inside the care worksheet
    // submission above (one act, one gesture); only cancelling an existing
    // request needs its own endpoint.
    Route::post('/care/orientations/{episodeOrientation}/consumables/{careConsumableRequest}/cancel', [CareController::class, 'cancelConsumables'])->name('care.consumables.cancel')->middleware('can:care_consumables.cancel');
    Route::post('/care/orientations/{episodeOrientation}/care-order-items/{careOrderItem}/not-performed', [CareController::class, 'markCareOrderItemNotPerformed'])->name('care.care-order-items.not-performed')->middleware('can:care.update');

    Route::get('/maternity', [MaternityController::class, 'index'])->name('maternity.index')->middleware('can:maternity.view');
    Route::get('/maternity/orientations/{episodeOrientation}', [MaternityController::class, 'show'])->name('maternity.orientations.show')->middleware('can:maternity.view');
    Route::post('/maternity/orientations/{episodeOrientation}/accept', [MaternityController::class, 'accept'])->name('maternity.orientations.accept')->middleware('can:maternity.update');
    Route::put('/maternity/orientations/{episodeOrientation}/record', [MaternityController::class, 'save'])->name('maternity.orientations.record.update')->middleware('can:maternity.view');
    Route::post('/maternity/orientations/{episodeOrientation}/procedures', [MaternityController::class, 'procedure'])->name('maternity.orientations.procedures.store')->middleware('can:maternity.procedures.manage');
    Route::post('/maternity/orientations/{episodeOrientation}/cesarean', [MaternityController::class, 'cesarean'])->name('maternity.orientations.cesarean.store')->middleware('can:maternity.delivery.manage');
    Route::post('/maternity/orientations/{episodeOrientation}/complete', [MaternityController::class, 'complete'])->name('maternity.orientations.complete')->middleware('can:maternity.complete');

    Route::get('/medicine', [MedicineController::class, 'index'])->name('medicine.index')->middleware('can:consultations.view');
    // Toutes les demandes d'examens du médecin, hors d'une consultation
    // précise : `laboratory_orders.view` suffit à entrer, et chaque famille
    // est ensuite filtrée par son propre droit dans le contrôleur.
    Route::get('/medicine/demandes-examens', [ParaclinicalRequestDirectoryController::class, 'index'])->name('medicine.paraclinical-requests.index')->middleware('can:paraclinical_requests.view');
    Route::get('/medicine/orientations/{episodeOrientation}', [MedicineController::class, 'begin'])->name('medicine.orientations.show')->middleware('can:consultations.view');
    Route::get('/medicine/orientations/{episodeOrientation}/{step}', [MedicineController::class, 'show'])
        // 'diagnostic' et 'decision' restent acceptées pour ne pas casser un
        // signet : le contrôleur les redirige vers l'écran qui porte
        // désormais leur fonction (ADR-081, ADR-084).
        ->whereIn('step', ['dossier', 'consultation', 'examen', 'paraclinique', 'diagnostic', 'ordonnance', 'decision', 'cloture'])
        ->name('medicine.orientations.step')
        ->middleware('can:consultations.view');
    // Autosaved typing across the consultation wizard: survives a reload,
    // discarded only by the doctor.
    Route::put('/medicine/orientations/{episodeOrientation}/draft', [MedicineController::class, 'saveDraft'])->name('medicine.orientations.draft.update')->middleware('can:consultations.view');
    Route::delete('/medicine/orientations/{episodeOrientation}/draft', [MedicineController::class, 'discardDraft'])->name('medicine.orientations.draft.destroy')->middleware('can:consultations.view');
    Route::post('/medicine/orientations/{episodeOrientation}/accept', [MedicineController::class, 'accept'])->name('medicine.orientations.accept')->middleware('can:consultations.create');
    Route::post('/medicine/orientations/{episodeOrientation}/urgence', [EpisodeEmergencyController::class, 'fromMedicine'])
        ->name('medicine.orientations.emergency.store')
        ->middleware('can:episodes.mark_emergency');
    Route::put('/medicine/orientations/{episodeOrientation}/interrogatoire', [MedicineController::class, 'updateInterview'])->name('medicine.interview.update')->middleware('can:consultations.update');
    Route::put('/medicine/orientations/{episodeOrientation}/examen-clinique', [MedicineController::class, 'updateClinicalExam'])->name('medicine.clinical-exam.update')->middleware('can:consultations.update');
    // Corriger une constante des Soins depuis la consultation (ADR-093).
    // `vitals.update`, pas `consultations.update` : ce qui est écrit est la
    // fiche Soins, et c'est ce droit-là qui la gouverne partout ailleurs.
    Route::put('/medicine/orientations/{episodeOrientation}/constantes', [MedicineController::class, 'correctCareVitals'])->name('medicine.care-vitals.correct')->middleware('can:vitals.update');
    // La conduite à tenir est une donnée, pas une étape : elle se choisit
    // dès que le médecin en sait assez, et ouvre aussitôt sa demande.
    Route::post('/medicine/orientations/{episodeOrientation}/orientation', [MedicineController::class, 'selectOrientation'])->name('medicine.orientation.select')->middleware('can:consultations.update');
    // L'étape est résolue explicitement par le médecin : validée, ou
    // déclarée non nécessaire pour ce patient. Jamais un effet de bord de
    // l'ouverture d'un écran.
    Route::post('/medicine/orientations/{episodeOrientation}/complementary-exams', [MedicineController::class, 'decideComplementaryExams'])->name('medicine.complementary-exams.decide')->middleware('can:consultations.update');
    Route::post('/medicine/orientations/{episodeOrientation}/diagnostic-timing', [MedicineController::class, 'decideDiagnosisTiming'])->name('medicine.diagnosis-timing.decide')->middleware('can:consultations.update');
    Route::post('/medicine/orientations/{episodeOrientation}/steps', [MedicineController::class, 'resolveStep'])->name('medicine.steps.resolve')->middleware('can:consultations.update');
    Route::post('/medicine/orientations/{episodeOrientation}/complete', [MedicineController::class, 'completeConsultation'])->name('medicine.consultations.complete')->middleware('can:consultations.update');
    Route::post('/medicine/orientations/{episodeOrientation}/reopen', [MedicineController::class, 'reopenConsultation'])->name('medicine.consultations.reopen')->middleware('can:consultations.reopen');
    Route::post('/medicine/orientations/{episodeOrientation}/diagnoses', [MedicineController::class, 'storeDiagnosis'])->name('medicine.diagnoses.store')->middleware('can:diagnoses.create');
    Route::get('/diagnostic-catalog/search', DiagnosticCatalogSearchController::class)->name('diagnostic-catalog.search')->middleware('can:diagnoses.create');
    Route::put('/medicine/orientations/{episodeOrientation}/diagnoses', [MedicineController::class, 'updateDiagnosis'])->name('medicine.diagnoses.update')->middleware('can:diagnoses.update');
    Route::post('/medicine/orientations/{episodeOrientation}/diagnoses/cancel', [MedicineController::class, 'cancelDiagnosis'])->name('medicine.diagnoses.cancel')->middleware('can:diagnoses.update');
    Route::post('/medicine/orientations/{episodeOrientation}/prescriptions', [MedicineController::class, 'storePrescription'])->name('medicine.prescriptions.store')->middleware('can:prescriptions.create');
    Route::put('/medicine/orientations/{episodeOrientation}/prescriptions/{prescription}', [MedicineController::class, 'updatePrescription'])->name('medicine.prescriptions.update')->middleware('can:prescriptions.update');
    Route::post('/medicine/orientations/{episodeOrientation}/prescriptions/{prescription}/cancel', [MedicineController::class, 'cancelPrescription'])->name('medicine.prescriptions.cancel')->middleware('can:prescriptions.cancel');
    Route::get('/medicine/orientations/{episodeOrientation}/prescriptions/{prescription}/print', [MedicineController::class, 'printPrescription'])->name('medicine.prescriptions.print')->middleware('can:prescriptions.view');
    Route::post('/medicine/orientations/{episodeOrientation}/care-orders', [MedicineController::class, 'storeCareOrder'])->name('medicine.care-orders.store')->middleware('can:care_orders.create');
    Route::post('/medicine/orientations/{episodeOrientation}/lab-requests', [MedicineController::class, 'storeLabRequest'])->name('medicine.lab-requests.store')->middleware('can:laboratory_orders.create');
    Route::post('/medicine/orientations/{episodeOrientation}/imaging-requests', [MedicineController::class, 'storeImagingRequest'])->name('medicine.imaging-requests.store')->middleware('can:imaging_orders.create');
    // Retrait d'une demande d'examen précise. `consultations.update` et non
    // un droit d'annulation propre : revenir sur une demande fait partie de
    // l'écriture de la consultation (ADR-079).
    Route::post('/medicine/orientations/{episodeOrientation}/paraclinical-requests/cancel', [MedicineController::class, 'cancelParaclinicalRequest'])->name('medicine.paraclinical-requests.cancel')->middleware('can:consultations.update');
    Route::post('/medicine/orientations/{episodeOrientation}/imaging-requests/{imagingRequestItem}/result', [MedicineController::class, 'recordImagingResult'])->name('medicine.imaging-requests.result')->middleware('can:imaging_results.create');
    Route::get('/medicine/imaging-requests/{imagingRequestItem}/compte-rendu', [MedicineController::class, 'printImagingReport'])->name('medicine.imaging-reports.print')->middleware('can:imaging_orders.view');
    Route::post('/medicine/orientations/{episodeOrientation}/surgical-referrals', [MedicineController::class, 'storeSurgicalReferral'])->name('medicine.surgical-referrals.store')->middleware('can:surgery.request');
    Route::post('/medicine/orientations/{episodeOrientation}/referrals', [MedicineController::class, 'storeReferral'])->name('medicine.referrals.store');
    Route::post('/medicine/orientations/{episodeOrientation}/hospitalization-requests', [MedicineController::class, 'storeHospitalizationRequest'])->name('medicine.hospitalization-requests.store')->middleware('can:hospitalization.request');
    Route::post('/medicine/orientations/{episodeOrientation}/medical-referrals', [MedicineController::class, 'storeMedicalReferral'])->name('medicine.medical-referrals.store')->middleware('can:transfer.request');
    Route::get('/medicine/orientations/{episodeOrientation}/hospitalization-requests/{hospitalizationRequest}/print', [MedicineController::class, 'printHospitalizationRequest'])->name('medicine.hospitalization-requests.print')->middleware('can:hospitalization.request');
    Route::get('/medicine/orientations/{episodeOrientation}/medical-referrals/{medicalReferral}/print', [MedicineController::class, 'printMedicalReferral'])->name('medicine.medical-referrals.print')->middleware('can:transfer.request');
    Route::post('/medicine/orientations/{episodeOrientation}/discharge', [MedicineController::class, 'discharge'])->name('medicine.discharge.store')->middleware('can:medical_discharge.create');

    // Laboratoire — minimal, côté suivi/résultat uniquement : la demande
    // vient de Médecine (CreateLabRequestAction), l'orientation existe déjà.
    Route::get('/laboratory', [LaboratoryController::class, 'index'])->name('laboratory.index')->middleware('can:laboratory_results.view');
    Route::post('/laboratory/items/{labRequestItem}/result', [LaboratoryController::class, 'recordResult'])->name('laboratory.items.result')->middleware('can:laboratory_results.create');

    // Espace anesthésiste autonome. Il partage les mêmes dossiers cliniques
    // avec Chirurgie mais n'accorde jamais implicitement surgery.view.
    Route::get('/anesthesia', [AnesthesiaWorkspaceController::class, 'index'])->name('anesthesia.index')->middleware('can:anesthesia.view');
    Route::get('/anesthesia/{surgicalRequest}', [AnesthesiaWorkspaceController::class, 'show'])->name('anesthesia.show')->middleware('can:anesthesia.view');

    // Chirurgie (CDC GitHub §15/16). Each middleware name matches exactly
    // one seeded permission (PermissionSeeder) — see SurgeryController's
    // own doc comment for why this is split across several controllers.
    Route::get('/surgery', [SurgeryController::class, 'index'])->name('surgery.index')->middleware('can:surgery.view');
    Route::get('/surgery/create', [SurgeryController::class, 'create'])->name('surgery.create.form')->middleware('can:surgery.create');
    Route::post('/surgery', [SurgeryController::class, 'store'])->name('surgery.store')->middleware('can:surgery.create');
    Route::get('/surgery/{surgicalRequest}', [SurgeryController::class, 'show'])->name('surgery.show')->middleware('can:surgery.view');
    Route::put('/surgery/{surgicalRequest}', [SurgeryController::class, 'update'])->name('surgery.update')->middleware('can:surgery.update');
    Route::post('/surgery/{surgicalRequest}/schedule', [SurgeryController::class, 'schedule'])->name('surgery.schedule')->middleware('can:surgery.schedule');
    Route::post('/surgery/{surgicalRequest}/preparation', [SurgeryController::class, 'updatePreparation'])->name('surgery.preparation.update')->middleware('can:surgery.preparation.update');
    Route::put('/surgery/{surgicalRequest}/block-entry', [SurgicalBlockEntryController::class, 'update'])->name('surgery.block-entry.update')->middleware('can:surgery.preparation.update');
    Route::post('/surgery/{surgicalRequest}/discharge', [SurgeryController::class, 'discharge'])->name('surgery.discharge')->middleware('can:surgery.discharge.create');

    Route::post('/surgery/{surgicalRequest}/preoperative/validate', [SurgicalPreoperativeController::class, 'validatePreoperative'])->name('surgery.preoperative.validate')->middleware('can:surgery.preoperative.validate');

    Route::post('/surgery/{surgicalRequest}/team', [SurgicalTeamMemberController::class, 'store'])->name('surgery.team.store')->middleware('can:surgery.update');
    Route::delete('/surgery/{surgicalRequest}/team/{teamMember}', [SurgicalTeamMemberController::class, 'destroy'])->scopeBindings()->name('surgery.team.destroy')->middleware('can:surgery.update');

    Route::post('/surgery/{surgicalRequest}/intervention', [SurgicalInterventionController::class, 'store'])->name('surgery.intervention.store')->middleware('can:surgery.intervention.create');
    Route::put('/surgery/{surgicalRequest}/intervention/{intervention}', [SurgicalInterventionController::class, 'update'])->name('surgery.intervention.update')->middleware('can:surgery.intervention.update');
    Route::put('/surgery/{surgicalRequest}/block-exit', [SurgicalBlockExitController::class, 'update'])->name('surgery.block-exit.update')->middleware('can:surgery.intervention.update');

    Route::post('/surgery/{surgicalRequest}/anesthesia', [AnesthesiaController::class, 'store'])->name('surgery.anesthesia.store')->middleware('can:anesthesia.create');
    Route::put('/surgery/{surgicalRequest}/anesthesia/{anesthesiaRecord}', [AnesthesiaController::class, 'update'])->name('surgery.anesthesia.update')->middleware('can:anesthesia.update');
    Route::post('/surgery/{surgicalRequest}/anesthesia/{anesthesiaRecord}/assessment/validate', [AnesthesiaController::class, 'validateAssessment'])->name('surgery.anesthesia.assessment.validate')->middleware('can:anesthesia.validate');
    Route::post('/surgery/{surgicalRequest}/anesthesia/{anesthesiaRecord}/validate', [AnesthesiaController::class, 'validateRecord'])->name('surgery.anesthesia.validate')->middleware('can:anesthesia.validate');

    Route::post('/surgery/{surgicalRequest}/report', [SurgicalReportController::class, 'store'])->name('surgery.report.store')->middleware('can:surgery.report.create');
    Route::put('/surgery/{surgicalRequest}/report/{report}', [SurgicalReportController::class, 'update'])->name('surgery.report.update')->middleware('can:surgery.report.update');
    Route::post('/surgery/{surgicalRequest}/report/{report}/validate', [SurgicalReportController::class, 'validateReport'])->name('surgery.report.validate')->middleware('can:surgery.report.validate');

    Route::post('/surgery/{surgicalRequest}/complications', [SurgicalComplicationController::class, 'store'])->name('surgery.complications.store')->middleware('can:surgery.complications.create');
    Route::post('/surgery/{surgicalRequest}/consumables', [SurgicalConsumableController::class, 'store'])->name('surgery.consumables.store')->middleware('can:surgery.consumables.create');
    Route::delete('/surgery/{surgicalRequest}/consumables/{consumable}', [SurgicalConsumableController::class, 'destroy'])->scopeBindings()->name('surgery.consumables.destroy')->middleware('can:surgery.consumables.create');
    Route::post('/surgery/{surgicalRequest}/care-notes/perioperative', [SurgicalCareNoteController::class, 'storePerioperative'])->name('surgery.care-notes.perioperative')->middleware('can:surgery.care.create');
    Route::post('/surgery/{surgicalRequest}/care-notes/postoperative', [SurgicalCareNoteController::class, 'storePostoperative'])->name('surgery.care-notes.postoperative')->middleware('can:surgery.postoperative_care.create');

    Route::post('/surgery/{surgicalRequest}/preliminary-treatments', [SurgicalTreatmentItemController::class, 'storePreliminary'])->name('surgery.preliminary-treatments.store')->middleware('can:surgery.preparation.update');
    Route::delete('/surgery/{surgicalRequest}/preliminary-treatments/{treatmentItem}', [SurgicalTreatmentItemController::class, 'destroyPreliminary'])->scopeBindings()->name('surgery.preliminary-treatments.destroy')->middleware('can:surgery.preparation.update');
    Route::post('/surgery/{surgicalRequest}/postoperative-treatments', [SurgicalTreatmentItemController::class, 'storePostoperative'])->name('surgery.postoperative-treatments.store')->middleware('can:surgery.postoperative_care.create');
    Route::delete('/surgery/{surgicalRequest}/postoperative-treatments/{treatmentItem}', [SurgicalTreatmentItemController::class, 'destroyPostoperative'])->scopeBindings()->name('surgery.postoperative-treatments.destroy')->middleware('can:surgery.postoperative_care.create');
    Route::post('/surgery/{surgicalRequest}/postoperative-observations', [SurgicalPostoperativeObservationController::class, 'store'])->name('surgery.postoperative-observations.store')->middleware('can:surgery.postoperative_care.create');
    Route::delete('/surgery/{surgicalRequest}/postoperative-observations/{observation}', [SurgicalPostoperativeObservationController::class, 'destroy'])->scopeBindings()->name('surgery.postoperative-observations.destroy')->middleware('can:surgery.postoperative_care.create');
});
