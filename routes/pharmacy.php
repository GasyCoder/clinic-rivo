<?php

use App\Http\Controllers\Pharmacy\CareConsumableController as PharmacyCareConsumableController;
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
use Illuminate\Support\Facades\Route;

/*
 * La Pharmacie d'un site (ADR-098), écrite une seule fois et servie deux fois
 * (ADR-189, sur le modèle des RH, ADR-187) :
 *
 *   /pharmacy/...                         la Pharmacie du site, pour ses comptes
 *   /api/v1/super-admin/site-pharmacy/... la même, pour le Super Admin du
 *                                         portail, derrière le jeton du site
 *
 * Mêmes contrôleurs, mêmes droits (`can:`), mêmes actions. Les actes physiques
 * — délivrer, servir un consommable, entrer en stock, faire l'inventaire,
 * ajuster, réceptionner, constater une rupture, imprimer le ticket du patient —
 * portent `rivo.site-only` : ils restent au site, par la personne qui a les
 * produits en main (ADR-098, ADR-179).
 */

// ADR-098 — Pharmacie : une vraie page par tâche, le menu latéral comme
// seule navigation. Chaque page porte la permission de l'écran qu'elle ouvre.
Route::get('/', PharmacyDashboardController::class)->name('index')->middleware('can:pharmacy.view');

Route::get('/stock', [PharmacyStockController::class, 'index'])
    ->name('stock.index')->middleware('can:view-pharmacy-catalog');
Route::get('/stock/entries/create', [PharmacyStockController::class, 'createEntry'])
    ->name('stock.entries.create')->middleware('can:stock.entry')->middleware('rivo.site-only');
// ADR-180, ADR-182 — l'écran d'entrée en stock est unique, et n'y entre que
// la marchandise réceptionnée : aucune entrée hors d'une livraison.
Route::post('/stock/entries/batch', [PharmacyStockController::class, 'storeEntries'])
    ->name('stock.entries.batch')->middleware('can:stock.entry')->middleware('rivo.site-only');
Route::get('/stock/inventory', [PharmacyStockController::class, 'inventory'])
    ->name('stock.inventory')->middleware('can:stock.adjust')->middleware('rivo.site-only');
Route::post('/stock/inventory', [PharmacyStockController::class, 'storeInventory'])
    ->name('stock.inventory.store')->middleware('can:stock.adjust')->middleware('rivo.site-only');
Route::get('/stock/adjustments/create', [PharmacyStockController::class, 'createAdjustment'])
    ->name('stock.adjustments.create')->middleware('can:stock.adjust')->middleware('rivo.site-only');
Route::post('/stock/adjustments', [PharmacyStockController::class, 'storeAdjustment'])
    ->name('stock.adjustments.store')->middleware('can:stock.adjust')->middleware('rivo.site-only');
Route::get('/stock/{medicine}', [PharmacyStockController::class, 'show'])
    ->name('stock.show')->middleware('can:stock.view');

// ADR-104 — la vente comptoir anonyme est retirée : toute vente de
// médicament est prise à la Réception, sur un dossier patient et un
// passage. L'URL reste valide et mène là où le travail se fait
// désormais ; les ventes déjà enregistrées restent lisibles.
Route::get('/counter-sales/create', fn () => redirect()
    ->route('reception.patients.create')
    ->with('status', 'La vente de médicaments se prend désormais à la Réception, sur un dossier patient.'))
    ->name('counter-sales.create')
    ->middleware('can:pharmacy.counter_sales.create');

Route::get('/dispenses', [PharmacyDispenseController::class, 'index'])
    ->name('dispenses.index')->middleware('can:prescriptions.view');
Route::post('/dispenses/{dispense}/invoice', [PharmacyDispenseController::class, 'prepareInvoice'])
    ->name('dispenses.invoice.store')->middleware('can:pharmacy.dispense.prepare_invoice')->middleware('rivo.site-only');
Route::get('/dispenses/{dispense}/ticket', [PharmacyDispenseController::class, 'ticket'])
    ->name('dispenses.ticket.show')->middleware('can:pharmacy.dispense.print')->middleware('rivo.site-only');
Route::post('/dispenses/{dispense}/deliveries', [PharmacyDispenseController::class, 'deliver'])
    ->name('dispenses.deliveries.store')->middleware('can:pharmacy.dispense')->middleware('rivo.site-only');

Route::get('/care-consumables', [PharmacyCareConsumableController::class, 'index'])
    ->name('care-consumables.index')->middleware('can:care_consumables.view');
Route::post('/care-consumables/{careConsumableRequest}/serve', [PharmacyCareConsumableController::class, 'serve'])
    ->name('care-consumables.serve')->middleware('can:care_consumables.serve')->middleware('rivo.site-only');

// ADR-098 — merged into « Médicaments & stock »; the address still works.
Route::get('/medicines', [PharmacyMedicineController::class, 'index'])
    ->name('medicines.index')->middleware('can:view-pharmacy-catalog');
Route::get('/purchases', PharmacyPurchasesController::class)
    ->name('purchases.index')->middleware('can:view-pharmacy-purchases');
Route::get('/medicines/create', [PharmacyMedicineController::class, 'create'])
    ->name('medicines.create')->middleware('can:medicines.create');
Route::post('/setup/categories', [PharmacyMedicineController::class, 'storeCategory'])
    ->name('setup.categories.store')->middleware('can:medicine_categories.create');
// ADR-098 — correcting the catalog: a medicine is deactivated, never deleted.
Route::get('/medicines/{medicine}/edit', [PharmacyMedicineController::class, 'edit'])
    ->name('medicines.edit')->middleware('can:medicines.update');
Route::put('/medicines/{medicine}', [PharmacyMedicineController::class, 'update'])
    ->name('medicines.update')->middleware('can:medicines.update');
// ADR-174 — the pharmacy sets its own sale price, nothing else.
Route::put('/medicines/{medicine}/sale-price', [PharmacyMedicineController::class, 'updateSalePrice'])
    ->name('medicines.sale-price')->middleware('can:medicines.sale_price.update');
Route::post('/medicines/{medicine}/deactivate', [PharmacyMedicineController::class, 'deactivate'])
    ->name('medicines.deactivate')->middleware('can:medicines.delete');
Route::post('/medicines/{medicine}/reactivate', [PharmacyMedicineController::class, 'reactivate'])
    ->name('medicines.reactivate')->middleware('can:medicines.restore');
Route::put('/setup/categories/{category}', [PharmacyMedicineController::class, 'updateCategory'])
    ->name('setup.categories.update')->middleware('can:medicine_categories.update');
Route::delete('/setup/categories/{category}', [PharmacyMedicineController::class, 'archiveCategory'])
    ->name('setup.categories.destroy')->middleware('can:medicine_categories.delete');
Route::post('/setup/categories/{category}/restore', [PharmacyMedicineController::class, 'restoreCategory'])
    ->name('setup.categories.restore')->middleware('can:medicine_categories.restore')->withTrashed();
Route::post('/setup/medicines', [PharmacyMedicineController::class, 'store'])
    ->name('setup.medicines.store')->middleware('can:medicines.create');
Route::get('/setup/medicines/import-template', [PharmacyMedicineController::class, 'template'])
    ->name('setup.medicines.import-template')->middleware('can:medicines.import');
Route::post('/setup/medicines/import', [PharmacyMedicineController::class, 'import'])
    ->name('setup.medicines.import')->middleware('can:medicines.import');

// ADR-097/098 — Fournisseurs : un dossier par fournisseur (catalogues,
// commandes, factures, prix), ouvert à la clinique en consultation.
Route::get('/suppliers/catalog-template', SupplierCatalogTemplateController::class)
    ->name('suppliers.catalog-template')->middleware('can:supplier_catalogs.view');
Route::get('/suppliers', [SupplierController::class, 'index'])
    ->name('suppliers.index')->middleware('can:medicine_suppliers.view');
Route::post('/setup/suppliers', [SupplierController::class, 'store'])
    ->name('setup.suppliers.store')->middleware('can:medicine_suppliers.create');
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])
    ->name('suppliers.show')->middleware('can:medicine_suppliers.view');
Route::get('/suppliers/{supplier}/catalogs', [SupplierController::class, 'catalogs'])
    ->name('suppliers.catalogs.index')->middleware('can:view-supplier-catalogs');
Route::get('/suppliers/{supplier}/catalogs/{catalog}/items', [SupplierController::class, 'catalogItems'])
    ->name('suppliers.catalogs.items')->middleware('can:view-supplier-catalogs')->withTrashed();
Route::get('/suppliers/{supplier}/products', [SupplierController::class, 'products'])
    ->name('suppliers.products')->middleware('can:view-supplier-offers');
Route::get('/suppliers/{supplier}/catalogs/{catalog}/import', [SupplierCatalogController::class, 'preview'])
    ->name('suppliers.catalogs.import.preview')->middleware('can:supplier_catalogs.create');

Route::post('/suppliers/{supplier}/catalogs', [SupplierCatalogController::class, 'store'])
    ->name('suppliers.catalogs.store')->middleware('can:supplier_catalogs.create');
Route::get('/suppliers/{supplier}/catalogs/{catalog}', [SupplierCatalogController::class, 'show'])
    ->name('suppliers.catalogs.show')->middleware('can:view-supplier-catalogs')->withTrashed();
Route::get('/suppliers/{supplier}/catalogs/{catalog}/download', [SupplierCatalogController::class, 'download'])
    ->name('suppliers.catalogs.download')->middleware('can:view-supplier-catalogs')->withTrashed();
Route::patch('/suppliers/{supplier}/catalogs/{catalog}', [SupplierCatalogController::class, 'update'])
    ->name('suppliers.catalogs.update')->middleware('can:supplier_catalogs.update');
Route::post('/suppliers/{supplier}/catalogs/{catalog}/activate', [SupplierCatalogController::class, 'activate'])
    ->name('suppliers.catalogs.activate')->middleware('can:supplier_catalogs.update');
Route::post('/suppliers/{supplier}/catalogs/{catalog}/import', [SupplierCatalogController::class, 'import'])
    ->name('suppliers.catalogs.import')->middleware('can:supplier_catalogs.create');
Route::delete('/suppliers/{supplier}/catalogs/{catalog}', [SupplierCatalogController::class, 'destroy'])
    ->name('suppliers.catalogs.destroy')->middleware('can:supplier_catalogs.delete');
Route::post('/suppliers/{supplier}/catalogs/{catalog}/restore', [SupplierCatalogController::class, 'restore'])
    ->name('suppliers.catalogs.restore')->middleware('can:supplier_catalogs.restore')->withTrashed();
Route::post('/suppliers/{supplier}/catalog-items/{catalogItem}/link', [SupplierCatalogController::class, 'linkItem'])
    ->name('suppliers.catalog-items.link')->middleware('can:set-medicine-supplier-offer');
// ADR-098 — a catalogue line is a transcription of the supplier's own
// document: it is corrected and withdrawn with the catalogue's rights.
Route::put('/suppliers/{supplier}/catalog-items/{catalogItem}', [SupplierCatalogController::class, 'updateItem'])
    ->name('suppliers.catalog-items.update')->middleware('can:supplier_catalogs.update');
Route::delete('/suppliers/{supplier}/catalog-items/{catalogItem}', [SupplierCatalogController::class, 'destroyItem'])
    ->name('suppliers.catalog-items.destroy')->middleware('can:supplier_catalogs.delete');
Route::post('/suppliers/{supplier}/catalog-items/{catalogItem}/restore', [SupplierCatalogController::class, 'restoreItem'])
    ->name('suppliers.catalog-items.restore')->middleware('can:supplier_catalogs.restore')->withTrashed();
Route::post('/suppliers/{supplier}/catalog-items/{catalogItem}/unlink', [SupplierCatalogController::class, 'unlinkItem'])
    ->name('suppliers.catalog-items.unlink')->middleware('can:medicine_supplier_offers.update');

Route::post('/suppliers/{supplier}/offers', [MedicineSupplierOfferController::class, 'store'])
    ->name('suppliers.offers.store')->middleware('can:set-medicine-supplier-offer');

Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
    ->name('purchase-orders.index')->middleware('can:view-supplier-orders');
Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])
    ->name('purchase-orders.create')->middleware('can:purchase_orders.create');
Route::post('/suppliers/{supplier}/purchase-orders', [PurchaseOrderController::class, 'store'])
    ->name('suppliers.purchase-orders.store')->middleware('can:purchase_orders.create');
Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
    ->name('purchase-orders.show')->middleware('can:view-supplier-orders');
Route::match(['put', 'patch'], '/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
    ->name('purchase-orders.update')->middleware('can:purchase_orders.update');
Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])
    ->name('purchase-orders.edit')->middleware('can:purchase_orders.update');
Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])
    ->name('purchase-orders.submit')->middleware('can:purchase_orders.submit');
Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
    ->name('purchase-orders.cancel')->middleware('can:purchase_orders.cancel');
Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])
    ->name('purchase-orders.destroy')->middleware('can:purchase_orders.delete');

// ADR-179 — la confirmation du fournisseur, les ruptures et la clôture.
// Constater (ou lever) une rupture ligne à ligne est un constat de réception :
// il reste au site. Clôturer toute la commande est une décision d'acheteur.
Route::post('/purchase-orders/{purchaseOrder}/confirmation', [PurchaseOrderController::class, 'confirm'])
    ->name('purchase-orders.confirm')->middleware('can:purchase_orders.confirm');
Route::delete('/purchase-orders/{purchaseOrder}/confirmation', [PurchaseOrderController::class, 'unconfirm'])
    ->name('purchase-orders.unconfirm')->middleware('can:purchase_orders.confirm');
Route::get('/purchase-orders/{purchaseOrder}/confirmation/document', [PurchaseOrderController::class, 'confirmationDocument'])
    ->name('purchase-orders.confirmation.document')->middleware('can:view-supplier-orders');
Route::post('/purchase-orders/{purchaseOrder}/lines/{line}/shortage', [PurchaseOrderController::class, 'shortage'])
    ->name('purchase-orders.lines.shortage')->middleware('can:goods_receipts.create')->middleware('rivo.site-only');
Route::delete('/purchase-orders/{purchaseOrder}/lines/{line}/shortage', [PurchaseOrderController::class, 'revertShortage'])
    ->name('purchase-orders.lines.shortage.revert')->middleware('can:goods_receipts.create')->middleware('rivo.site-only');
Route::post('/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])
    ->name('purchase-orders.close')->middleware('can:purchase_orders.cancel');

Route::get('/purchase-orders/{purchaseOrder}/receive', [GoodsReceiptController::class, 'create'])
    ->name('purchase-orders.receive')->middleware('can:goods_receipts.create')->middleware('rivo.site-only');
Route::post('/purchase-orders/{purchaseOrder}/receipts', [GoodsReceiptController::class, 'store'])
    ->name('purchase-orders.receipts.store')->middleware('can:goods_receipts.create')->middleware('rivo.site-only');
Route::get('/receipts', [GoodsReceiptController::class, 'index'])
    ->name('receipts.index')->middleware('can:goods_receipts.view');
Route::get('/receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
    ->name('receipts.show')->middleware('can:goods_receipts.view');
// ADR-175 — la facture d'une réception enregistrée sans elle.
Route::get('/receipts/{goodsReceipt}/invoice', [GoodsReceiptController::class, 'createInvoice'])
    ->name('receipts.invoice.create')->middleware('can:supplier_invoices.create');
Route::post('/receipts/{goodsReceipt}/invoice', [GoodsReceiptController::class, 'storeInvoice'])
    ->name('receipts.invoice.store')->middleware('can:supplier_invoices.create');

Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index'])
    ->name('supplier-invoices.index')->middleware('can:view-supplier-invoices');
Route::get('/supplier-invoices/create', [SupplierInvoiceController::class, 'create'])
    ->name('supplier-invoices.create')->middleware('can:supplier_invoices.create');
Route::post('/suppliers/{supplier}/invoices', [SupplierInvoiceController::class, 'store'])
    ->name('suppliers.invoices.store')->middleware('can:supplier_invoices.create');
Route::get('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'show'])
    ->name('supplier-invoices.show')->middleware('can:view-supplier-invoices');
Route::get('/supplier-invoices/{supplierInvoice}/attachment', [SupplierInvoiceController::class, 'attachment'])
    ->name('supplier-invoices.attachment')->middleware('can:view-supplier-invoices')->withTrashed();
Route::get('/supplier-invoices/{supplierInvoice}/edit', [SupplierInvoiceController::class, 'edit'])
    ->name('supplier-invoices.edit')->middleware('can:supplier_invoices.update');
// POST: a corrected invoice may carry a new document.
Route::post('/supplier-invoices/{supplierInvoice}/update', [SupplierInvoiceController::class, 'update'])
    ->name('supplier-invoices.update')->middleware('can:supplier_invoices.update');
Route::delete('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'destroy'])
    ->name('supplier-invoices.destroy')->middleware('can:supplier_invoices.delete');
Route::post('/supplier-invoices/{supplierInvoice}/restore', [SupplierInvoiceController::class, 'restore'])
    ->name('supplier-invoices.restore')->middleware('can:supplier_invoices.restore')->withTrashed();
