<?php

use App\Http\Controllers\Api\V1\SuperAdmin\AddressEntryController;
use App\Http\Controllers\Api\V1\SuperAdmin\CatalogController;
use App\Http\Controllers\Api\V1\SuperAdmin\MedicineStockController;
use App\Http\Controllers\Api\V1\SuperAdmin\MutualOrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['rivo.site-api', 'api.idempotent'])
    ->prefix('v1/super-admin')
    ->name('api.v1.super-admin.')
    ->group(function () {
        Route::get('/pharmacy/stock', MedicineStockController::class)->name('pharmacy.stock');
        Route::post('/pharmacy/stock/import', [MedicineStockController::class, 'import'])->name('pharmacy.stock.import');

        Route::get('/address-entries', [AddressEntryController::class, 'index'])->name('address-entries.index');
        Route::post('/address-entries', [AddressEntryController::class, 'store'])->name('address-entries.store');
        Route::post('/address-entries/import', [AddressEntryController::class, 'import'])->name('address-entries.import');
        Route::post('/address-entries/bulk/archive', [AddressEntryController::class, 'bulkArchive'])->name('address-entries.bulk.archive');
        Route::post('/address-entries/bulk/restore', [AddressEntryController::class, 'bulkRestore'])->name('address-entries.bulk.restore');
        Route::put('/address-entries/{addressUuid}', [AddressEntryController::class, 'update'])->name('address-entries.update');
        Route::delete('/address-entries/{addressUuid}', [AddressEntryController::class, 'destroy'])->name('address-entries.destroy');
        Route::post('/address-entries/{addressUuid}/restore', [AddressEntryController::class, 'restore'])->name('address-entries.restore');

        Route::get('/mutual-organizations', [MutualOrganizationController::class, 'index'])->name('mutual-organizations.index');
        Route::post('/mutual-organizations', [MutualOrganizationController::class, 'store'])->name('mutual-organizations.store');
        Route::post('/mutual-organizations/import', [MutualOrganizationController::class, 'import'])->name('mutual-organizations.import');
        Route::post('/mutual-organizations/bulk/archive', [MutualOrganizationController::class, 'bulkArchive'])->name('mutual-organizations.bulk.archive');
        Route::post('/mutual-organizations/bulk/restore', [MutualOrganizationController::class, 'bulkRestore'])->name('mutual-organizations.bulk.restore');
        Route::put('/mutual-organizations/{organizationUuid}', [MutualOrganizationController::class, 'update'])->name('mutual-organizations.update');
        Route::delete('/mutual-organizations/{organizationUuid}', [MutualOrganizationController::class, 'destroy'])->name('mutual-organizations.destroy');
        Route::post('/mutual-organizations/{organizationUuid}/restore', [MutualOrganizationController::class, 'restore'])->name('mutual-organizations.restore');

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
    });
