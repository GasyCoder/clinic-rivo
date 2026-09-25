<?php

use App\Http\Controllers\Api\V1\SuperAdmin\AddressEntryController;
use App\Http\Controllers\Api\V1\SuperAdmin\AnalysisCatalogController;
use App\Http\Controllers\Api\V1\SuperAdmin\CashRegisterController;
use App\Http\Controllers\Api\V1\SuperAdmin\CatalogController;
use App\Http\Controllers\Api\V1\SuperAdmin\DocumentTemplateController;
use App\Http\Controllers\Api\V1\SuperAdmin\HospitalBedController;
use App\Http\Controllers\Api\V1\SuperAdmin\HumanResourcesController;
use App\Http\Controllers\Api\V1\SuperAdmin\MedicineStockController;
use App\Http\Controllers\Api\V1\SuperAdmin\MutualOrganizationController;
use App\Http\Controllers\Api\V1\SuperAdmin\PatientVipSettingsController;
use App\Http\Controllers\Api\V1\SuperAdmin\PaymentMethodController;
use App\Http\Controllers\Api\V1\SuperAdmin\PermissionController as SuperAdminPermissionController;
use App\Http\Controllers\Api\V1\SuperAdmin\PharmacyCatalogController;
use App\Http\Controllers\Api\V1\SuperAdmin\PharmacyProcurementController;
use App\Http\Controllers\Api\V1\SuperAdmin\PharmacySupplierController;
use App\Http\Controllers\Api\V1\SuperAdmin\ReportController as SuperAdminReportController;
use App\Http\Controllers\Api\V1\SuperAdmin\RoleController as SuperAdminRoleController;
use App\Http\Controllers\Api\V1\SuperAdmin\AppSettingsController;
use App\Http\Controllers\Api\V1\SuperAdmin\TrashController;
use App\Http\Controllers\Api\V1\SuperAdmin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['rivo.site-api', 'api.idempotent'])
    ->prefix('v1/super-admin')
    ->name('api.v1.super-admin.')
    ->group(function () {
        Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
        Route::post('/trash/{category}/{uuid}/restore', [TrashController::class, 'restore'])->name('trash.restore');
        Route::delete('/trash/{category}/{uuid}', [TrashController::class, 'destroy'])->name('trash.force-delete');

        Route::get('/pharmacy/stock', MedicineStockController::class)->name('pharmacy.stock');
        Route::post('/pharmacy/stock/import', [MedicineStockController::class, 'import'])->name('pharmacy.stock.import');
        // ADR-098 — medicine records and families, corrected from the portal.
        Route::get('/pharmacy/medicines/{medicineUuid}', [PharmacyCatalogController::class, 'showMedicine'])->name('pharmacy.medicines.show');
        Route::put('/pharmacy/medicines/{medicineUuid}', [PharmacyCatalogController::class, 'updateMedicine'])->name('pharmacy.medicines.update');
        Route::post('/pharmacy/medicines/{medicineUuid}/deactivate', [PharmacyCatalogController::class, 'deactivateMedicine'])->name('pharmacy.medicines.deactivate');
        Route::post('/pharmacy/medicines/{medicineUuid}/reactivate', [PharmacyCatalogController::class, 'reactivateMedicine'])->name('pharmacy.medicines.reactivate');
        Route::get('/pharmacy/categories', [PharmacyCatalogController::class, 'categories'])->name('pharmacy.categories.index');
        Route::post('/pharmacy/categories', [PharmacyCatalogController::class, 'storeCategory'])->name('pharmacy.categories.store');
        Route::put('/pharmacy/categories/{categoryUuid}', [PharmacyCatalogController::class, 'updateCategory'])->name('pharmacy.categories.update');
        Route::delete('/pharmacy/categories/{categoryUuid}', [PharmacyCatalogController::class, 'archiveCategory'])->name('pharmacy.categories.archive');
        Route::post('/pharmacy/categories/{categoryUuid}/restore', [PharmacyCatalogController::class, 'restoreCategory'])->name('pharmacy.categories.restore');

        // ADR-098 — supplier folders and their catalogs, managed from the portal.
        Route::get('/pharmacy/suppliers', [PharmacySupplierController::class, 'index'])->name('pharmacy.suppliers.index');
        Route::post('/pharmacy/suppliers', [PharmacySupplierController::class, 'store'])->name('pharmacy.suppliers.store');
        Route::post('/pharmacy/suppliers/import-preview', [PharmacySupplierController::class, 'previewSuppliersImport'])->name('pharmacy.suppliers.import.preview');
        Route::post('/pharmacy/suppliers/import', [PharmacySupplierController::class, 'importSuppliers'])->name('pharmacy.suppliers.import');
        // Before the {supplierUuid} routes: this one compares every supplier.
        Route::get('/pharmacy/supplier-offers', [PharmacySupplierController::class, 'offers'])->name('pharmacy.supplier-offers');
        Route::get('/pharmacy/suppliers/{supplierUuid}', [PharmacySupplierController::class, 'show'])->name('pharmacy.suppliers.show');
        Route::get('/pharmacy/suppliers/{supplierUuid}/orders', [PharmacySupplierController::class, 'orders'])->name('pharmacy.suppliers.orders');
        // ADR-098 — orders and invoices from the portal; receiving stays at the site.
        Route::get('/pharmacy/suppliers/{supplierUuid}/order-form', [PharmacyProcurementController::class, 'orderForm'])->name('pharmacy.suppliers.orders.form');
        Route::post('/pharmacy/suppliers/{supplierUuid}/orders', [PharmacyProcurementController::class, 'storeOrder'])->name('pharmacy.suppliers.orders.store');
        Route::get('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}', [PharmacyProcurementController::class, 'showOrder'])->name('pharmacy.suppliers.orders.show');
        Route::put('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}', [PharmacyProcurementController::class, 'updateOrder'])->name('pharmacy.suppliers.orders.update');
        Route::post('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}/submit', [PharmacyProcurementController::class, 'submitOrder'])->name('pharmacy.suppliers.orders.submit');
        Route::post('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}/cancel', [PharmacyProcurementController::class, 'cancelOrder'])->name('pharmacy.suppliers.orders.cancel');
        // ADR-179 — la confirmation du fournisseur et la clôture des reliquats :
        // deux gestes d'acheteur, là où les commandes se passent. Constater une
        // rupture ligne à ligne reste au site (ADR-176).
        Route::post('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}/confirmation', [PharmacyProcurementController::class, 'confirmOrder'])->name('pharmacy.suppliers.orders.confirm');
        Route::delete('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}/confirmation', [PharmacyProcurementController::class, 'unconfirmOrder'])->name('pharmacy.suppliers.orders.unconfirm');
        Route::get('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}/confirmation/document', [PharmacyProcurementController::class, 'orderConfirmationDocument'])->name('pharmacy.suppliers.orders.confirmation.document');
        Route::post('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}/close', [PharmacyProcurementController::class, 'closeOrder'])->name('pharmacy.suppliers.orders.close');
        Route::delete('/pharmacy/suppliers/{supplierUuid}/orders/{orderUuid}', [PharmacyProcurementController::class, 'trashOrder'])->name('pharmacy.suppliers.orders.trash');
        Route::get('/pharmacy/suppliers/{supplierUuid}/invoice-form', [PharmacyProcurementController::class, 'invoiceForm'])->name('pharmacy.suppliers.invoices.form');
        Route::post('/pharmacy/suppliers/{supplierUuid}/invoices', [PharmacyProcurementController::class, 'storeInvoice'])->name('pharmacy.suppliers.invoices.store');
        Route::get('/pharmacy/suppliers/{supplierUuid}/invoices/{invoiceUuid}', [PharmacyProcurementController::class, 'showInvoice'])->name('pharmacy.suppliers.invoices.show');
        Route::post('/pharmacy/suppliers/{supplierUuid}/invoices/{invoiceUuid}/update', [PharmacyProcurementController::class, 'updateInvoice'])->name('pharmacy.suppliers.invoices.update');
        Route::delete('/pharmacy/suppliers/{supplierUuid}/invoices/{invoiceUuid}', [PharmacyProcurementController::class, 'archiveInvoice'])->name('pharmacy.suppliers.invoices.archive');
        Route::post('/pharmacy/suppliers/{supplierUuid}/invoices/{invoiceUuid}/restore', [PharmacyProcurementController::class, 'restoreInvoice'])->name('pharmacy.suppliers.invoices.restore');
        Route::get('/pharmacy/suppliers/{supplierUuid}/invoices', [PharmacySupplierController::class, 'invoices'])->name('pharmacy.suppliers.invoices');
        Route::get('/pharmacy/suppliers/{supplierUuid}/products', [PharmacySupplierController::class, 'products'])->name('pharmacy.suppliers.products');
        Route::put('/pharmacy/suppliers/{supplierUuid}', [PharmacySupplierController::class, 'update'])->name('pharmacy.suppliers.update');
        Route::delete('/pharmacy/suppliers/{supplierUuid}', [PharmacySupplierController::class, 'archive'])->name('pharmacy.suppliers.archive');
        Route::post('/pharmacy/suppliers/{supplierUuid}/restore', [PharmacySupplierController::class, 'restore'])->name('pharmacy.suppliers.restore');
        Route::get('/pharmacy/suppliers/{supplierUuid}/catalogs', [PharmacySupplierController::class, 'catalogs'])->name('pharmacy.suppliers.catalogs.index');
        Route::post('/pharmacy/suppliers/{supplierUuid}/catalogs', [PharmacySupplierController::class, 'uploadCatalog'])->name('pharmacy.suppliers.catalogs.store');
        Route::post('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/activate', [PharmacySupplierController::class, 'activateCatalog'])->name('pharmacy.suppliers.catalogs.activate');
        Route::get('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/download', [PharmacySupplierController::class, 'downloadCatalog'])->name('pharmacy.suppliers.catalogs.download');
        Route::get('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/items', [PharmacySupplierController::class, 'catalogItems'])->name('pharmacy.suppliers.catalogs.items');
        Route::put('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/items/{itemUuid}', [PharmacySupplierController::class, 'updateCatalogItem'])->name('pharmacy.suppliers.catalogs.items.update');
        Route::delete('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/items/{itemUuid}', [PharmacySupplierController::class, 'archiveCatalogItem'])->name('pharmacy.suppliers.catalogs.items.destroy');
        Route::post('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/items/{itemUuid}/restore', [PharmacySupplierController::class, 'restoreCatalogItem'])->name('pharmacy.suppliers.catalogs.items.restore');
        Route::post('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/items/{itemUuid}/link', [PharmacySupplierController::class, 'linkCatalogItem'])->name('pharmacy.suppliers.catalogs.items.link');
        Route::post('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/items/{itemUuid}/unlink', [PharmacySupplierController::class, 'unlinkCatalogItem'])->name('pharmacy.suppliers.catalogs.items.unlink');
        Route::patch('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}', [PharmacySupplierController::class, 'updateCatalog'])->name('pharmacy.suppliers.catalogs.update');
        Route::delete('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}', [PharmacySupplierController::class, 'archiveCatalog'])->name('pharmacy.suppliers.catalogs.destroy');
        Route::post('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/restore', [PharmacySupplierController::class, 'restoreCatalog'])->name('pharmacy.suppliers.catalogs.restore');
        Route::get('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/import-preview', [PharmacySupplierController::class, 'previewImport'])->name('pharmacy.suppliers.catalogs.import.preview');
        Route::post('/pharmacy/suppliers/{supplierUuid}/catalogs/{catalogUuid}/import', [PharmacySupplierController::class, 'import'])->name('pharmacy.suppliers.catalogs.import');

        Route::get('/human-resources', HumanResourcesController::class)->name('human-resources.index');

        Route::get('/address-entries', [AddressEntryController::class, 'index'])->name('address-entries.index');
        Route::post('/address-entries', [AddressEntryController::class, 'store'])->name('address-entries.store');
        Route::post('/address-entries/import', [AddressEntryController::class, 'import'])->name('address-entries.import');
        Route::post('/address-entries/bulk/archive', [AddressEntryController::class, 'bulkArchive'])->name('address-entries.bulk.archive');
        Route::post('/address-entries/bulk/restore', [AddressEntryController::class, 'bulkRestore'])->name('address-entries.bulk.restore');
        Route::put('/address-entries/{addressUuid}', [AddressEntryController::class, 'update'])->name('address-entries.update');
        Route::delete('/address-entries/{addressUuid}', [AddressEntryController::class, 'destroy'])->name('address-entries.destroy');
        Route::post('/address-entries/{addressUuid}/restore', [AddressEntryController::class, 'restore'])->name('address-entries.restore');

        Route::get('/analysis-catalogs', [AnalysisCatalogController::class, 'index'])->name('analysis-catalogs.index');
        Route::post('/analysis-catalogs', [AnalysisCatalogController::class, 'store'])->name('analysis-catalogs.store');
        Route::post('/analysis-catalogs/import', [AnalysisCatalogController::class, 'import'])->name('analysis-catalogs.import');
        Route::get('/analysis-catalogs/{analysisUuid}', [AnalysisCatalogController::class, 'show'])->name('analysis-catalogs.show');
        Route::put('/analysis-catalogs/{analysisUuid}', [AnalysisCatalogController::class, 'update'])->name('analysis-catalogs.update');
        Route::post('/analysis-catalogs/{analysisUuid}/activate', [AnalysisCatalogController::class, 'activate'])->name('analysis-catalogs.activate');
        Route::post('/analysis-catalogs/{analysisUuid}/deactivate', [AnalysisCatalogController::class, 'deactivate'])->name('analysis-catalogs.deactivate');

        Route::get('/mutual-organizations', [MutualOrganizationController::class, 'index'])->name('mutual-organizations.index');
        Route::post('/mutual-organizations', [MutualOrganizationController::class, 'store'])->name('mutual-organizations.store');
        Route::post('/mutual-organizations/import', [MutualOrganizationController::class, 'import'])->name('mutual-organizations.import');
        Route::post('/mutual-organizations/bulk/archive', [MutualOrganizationController::class, 'bulkArchive'])->name('mutual-organizations.bulk.archive');
        Route::post('/mutual-organizations/bulk/restore', [MutualOrganizationController::class, 'bulkRestore'])->name('mutual-organizations.bulk.restore');
        Route::put('/mutual-organizations/{organizationUuid}', [MutualOrganizationController::class, 'update'])->name('mutual-organizations.update');
        Route::delete('/mutual-organizations/{organizationUuid}', [MutualOrganizationController::class, 'destroy'])->name('mutual-organizations.destroy');
        Route::post('/mutual-organizations/{organizationUuid}/restore', [MutualOrganizationController::class, 'restore'])->name('mutual-organizations.restore');

        Route::get('/cash-registers', [CashRegisterController::class, 'index'])->name('cash-registers.index');
        Route::post('/cash-registers', [CashRegisterController::class, 'store'])->name('cash-registers.store');
        Route::get('/cash-registers/{cashRegisterUuid}', [CashRegisterController::class, 'show'])->name('cash-registers.show');
        Route::put('/cash-registers/{cashRegisterUuid}', [CashRegisterController::class, 'update'])->name('cash-registers.update');
        Route::post('/cash-registers/{cashRegisterUuid}/session/lock', [CashRegisterController::class, 'lock'])->name('cash-registers.session.lock');
        Route::post('/cash-registers/{cashRegisterUuid}/session/unlock', [CashRegisterController::class, 'unlock'])->name('cash-registers.session.unlock');
        Route::post('/cash-registers/{cashRegisterUuid}/session/close', [CashRegisterController::class, 'close'])->name('cash-registers.session.close');
        Route::post('/cash-registers/{cashRegisterUuid}/activate', [CashRegisterController::class, 'activate'])->name('cash-registers.activate');
        Route::post('/cash-registers/{cashRegisterUuid}/deactivate', [CashRegisterController::class, 'deactivate'])->name('cash-registers.deactivate');
        Route::put('/cash-registers/{cashRegisterUuid}/payment-methods', [CashRegisterController::class, 'updatePaymentMethods'])->name('cash-registers.payment-methods.update');
        Route::delete('/cash-registers/{cashRegisterUuid}', [CashRegisterController::class, 'destroy'])->name('cash-registers.destroy');
        Route::post('/cash-registers/{cashRegisterUuid}/restore', [CashRegisterController::class, 'restore'])->name('cash-registers.restore');

        // ADR-164 — services, chambres et lits de ce site.
        Route::get('/hospital-beds', [HospitalBedController::class, 'index'])->name('hospital-beds.index');
        Route::post('/hospital-beds/services', [HospitalBedController::class, 'storeService'])->name('hospital-beds.services.store');
        Route::put('/hospital-beds/services/{serviceUuid}', [HospitalBedController::class, 'updateService'])->name('hospital-beds.services.update');
        Route::delete('/hospital-beds/services/{serviceUuid}', [HospitalBedController::class, 'archiveService'])->name('hospital-beds.services.archive');
        Route::post('/hospital-beds/services/{serviceUuid}/restore', [HospitalBedController::class, 'restoreService'])->name('hospital-beds.services.restore');
        Route::post('/hospital-beds/services/{serviceUuid}/rooms', [HospitalBedController::class, 'storeRoom'])->name('hospital-beds.rooms.store');
        Route::put('/hospital-beds/rooms/{roomUuid}', [HospitalBedController::class, 'updateRoom'])->name('hospital-beds.rooms.update');
        Route::post('/hospital-beds/rooms/{roomUuid}/beds', [HospitalBedController::class, 'addBeds'])->name('hospital-beds.rooms.beds.store');
        Route::delete('/hospital-beds/rooms/{roomUuid}', [HospitalBedController::class, 'archiveRoom'])->name('hospital-beds.rooms.archive');
        Route::post('/hospital-beds/rooms/{roomUuid}/restore', [HospitalBedController::class, 'restoreRoom'])->name('hospital-beds.rooms.restore');
        Route::put('/hospital-beds/beds/{bedUuid}', [HospitalBedController::class, 'updateBed'])->name('hospital-beds.beds.update');
        Route::post('/hospital-beds/beds/{bedUuid}/out-of-service', [HospitalBedController::class, 'outOfService'])->name('hospital-beds.beds.out-of-service');
        Route::post('/hospital-beds/beds/{bedUuid}/in-service', [HospitalBedController::class, 'inService'])->name('hospital-beds.beds.in-service');
        Route::delete('/hospital-beds/beds/{bedUuid}', [HospitalBedController::class, 'archiveBed'])->name('hospital-beds.beds.archive');
        Route::post('/hospital-beds/beds/{bedUuid}/restore', [HospitalBedController::class, 'restoreBed'])->name('hospital-beds.beds.restore');

        // ADR-184 — paramètres de l'application de ce site.
        Route::get('/app-settings', [AppSettingsController::class, 'show'])->name('app-settings.show');
        Route::put('/app-settings', [AppSettingsController::class, 'update'])->name('app-settings.update');
        Route::post('/app-settings/assets/{kind}', [AppSettingsController::class, 'storeAsset'])->name('app-settings.assets.store');
        Route::delete('/app-settings/assets/{kind}', [AppSettingsController::class, 'destroyAsset'])->name('app-settings.assets.destroy');

        // ADR-133 — seuils des patients VIP de ce site.
        Route::get('/patient-vip-settings', [PatientVipSettingsController::class, 'show'])->name('patient-vip-settings.show');
        Route::post('/patient-vip-settings/preview', [PatientVipSettingsController::class, 'preview'])->name('patient-vip-settings.preview');
        Route::put('/patient-vip-settings', [PatientVipSettingsController::class, 'update'])->name('patient-vip-settings.update');

        Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
        Route::put('/payment-methods/{paymentMethodUuid}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
        Route::post('/payment-methods/{paymentMethodUuid}/activate', [PaymentMethodController::class, 'activate'])->name('payment-methods.activate');
        Route::post('/payment-methods/{paymentMethodUuid}/deactivate', [PaymentMethodController::class, 'deactivate'])->name('payment-methods.deactivate');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/bulk/deactivate', [UserController::class, 'bulkDeactivate'])->name('users.bulk.deactivate');
        Route::post('/users/bulk/force-delete', [UserController::class, 'bulkForceDelete'])->name('users.bulk.force_delete');
        Route::put('/users/{userUuid}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{userUuid}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('/users/{userUuid}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::delete('/users/{userUuid}', [UserController::class, 'forceDelete'])->name('users.force_delete');
        // Le référentiel des rôles, distinct de la gestion des comptes
        // (ADR-100) : deux métiers, deux écrans, deux contrôleurs.
        Route::get('/roles', [SuperAdminRoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [SuperAdminRoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{roleCode}', [SuperAdminRoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{roleCode}', [SuperAdminRoleController::class, 'archive'])->name('roles.archive');
        Route::post('/roles/{roleCode}/restore', [SuperAdminRoleController::class, 'restore'])->name('roles.restore');
        Route::put('/roles/{roleCode}/permissions', [SuperAdminRoleController::class, 'updatePermissions'])->name('roles.permissions.update');
        Route::post('/roles/{roleCode}/permissions/reset', [SuperAdminRoleController::class, 'resetPermissions'])->name('roles.permissions.reset');
        Route::put('/roles/accounts/{userUuid}/permissions', [SuperAdminRoleController::class, 'updateUserPermissions'])->name('roles.accounts.permissions.update');
        Route::post('/roles/accounts/{userUuid}/permissions/reset', [SuperAdminRoleController::class, 'resetUserPermissions'])->name('roles.accounts.permissions.reset');

        // Le catalogue des permissions lui-même (ADR-101).
        // Le rapport consolidé du site, lu par le tableau de bord central
        // (ADR-102). Lecture seule, gardée section par section.
        Route::get('/reports/overview', [SuperAdminReportController::class, 'overview'])->name('reports.overview');

        Route::get('/permissions', [SuperAdminPermissionController::class, 'index'])->name('permissions.index');
        Route::post('/permissions', [SuperAdminPermissionController::class, 'store'])->name('permissions.store');
        Route::put('/permissions/{permissionId}', [SuperAdminPermissionController::class, 'update'])->name('permissions.update');
        Route::delete('/permissions/{permissionId}', [SuperAdminPermissionController::class, 'destroy'])->name('permissions.destroy');

        Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
        Route::post('/catalog', [CatalogController::class, 'store'])->name('catalog.store');
        Route::post('/catalog/tariffs/import', [CatalogController::class, 'importTariffs'])->name('catalog.tariffs.import');
        Route::post('/catalog/bulk/archive', [CatalogController::class, 'bulkArchive'])->name('catalog.bulk.archive');
        Route::post('/catalog/bulk/restore', [CatalogController::class, 'bulkRestore'])->name('catalog.bulk.restore');
        Route::put('/catalog/{catalogUuid}', [CatalogController::class, 'update'])->name('catalog.update');
        Route::delete('/catalog/{catalogUuid}', [CatalogController::class, 'destroy'])->name('catalog.destroy');
        Route::post('/catalog/{catalogUuid}/restore', [CatalogController::class, 'restore'])->name('catalog.restore');
        Route::post('/catalog/{catalogUuid}/tariffs', [CatalogController::class, 'setTariff'])->name('catalog.tariffs.store');
        Route::post('/catalog/{catalogUuid}/tariffs/archive', [CatalogController::class, 'archiveTariff'])->name('catalog.tariffs.archive');

        Route::get('/document-templates', [DocumentTemplateController::class, 'index'])->name('document-templates.index');
        Route::post('/document-templates', [DocumentTemplateController::class, 'store'])->name('document-templates.store');
        Route::get('/document-templates/{documentTemplateUuid}', [DocumentTemplateController::class, 'show'])->name('document-templates.show');
        Route::put('/document-templates/{documentTemplateUuid}', [DocumentTemplateController::class, 'update'])->name('document-templates.update');
        Route::delete('/document-templates/{documentTemplateUuid}', [DocumentTemplateController::class, 'destroy'])->name('document-templates.destroy');
        Route::post('/document-templates/{documentTemplateUuid}/restore', [DocumentTemplateController::class, 'restore'])->name('document-templates.restore');
        Route::post('/document-templates/{documentTemplateUuid}/duplicate', [DocumentTemplateController::class, 'duplicate'])->name('document-templates.duplicate');
        Route::get('/document-templates/{documentTemplateUuid}/history', [DocumentTemplateController::class, 'history'])->name('document-templates.history');
        Route::post('/document-templates/{documentTemplateUuid}/revert', [DocumentTemplateController::class, 'revert'])->name('document-templates.revert');
        Route::post('/document-templates/{documentTemplateUuid}/activate', [DocumentTemplateController::class, 'activate'])->name('document-templates.activate');
        Route::post('/document-templates/{documentTemplateUuid}/deactivate', [DocumentTemplateController::class, 'deactivate'])->name('document-templates.deactivate');
    });
