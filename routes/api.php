<?php

use App\Http\Controllers\Api\V1\SuperAdmin\AddressEntryController;
use App\Http\Controllers\Api\V1\SuperAdmin\CashRegisterController;
use App\Http\Controllers\Api\V1\SuperAdmin\CatalogController;
use App\Http\Controllers\Api\V1\SuperAdmin\HumanResourcesController;
use App\Http\Controllers\Api\V1\SuperAdmin\MedicineStockController;
use App\Http\Controllers\Api\V1\SuperAdmin\MutualOrganizationController;
use App\Http\Controllers\Api\V1\SuperAdmin\TrashController;
use App\Http\Controllers\Api\V1\SuperAdmin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['rivo.site-api', 'api.idempotent'])
    ->prefix('v1/super-admin')
    ->name('api.v1.super-admin.')
    ->group(function () {
        Route::get('/trash', [TrashController::class, 'index'])->name('trash.index');
        Route::post('/trash/{category}/{uuid}/restore', [TrashController::class, 'restore'])->name('trash.restore');

        Route::get('/pharmacy/stock', MedicineStockController::class)->name('pharmacy.stock');
        Route::post('/pharmacy/stock/import', [MedicineStockController::class, 'import'])->name('pharmacy.stock.import');

        Route::get('/human-resources', HumanResourcesController::class)->name('human-resources.index');

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

        Route::get('/cash-registers', [CashRegisterController::class, 'index'])->name('cash-registers.index');
        Route::post('/cash-registers', [CashRegisterController::class, 'store'])->name('cash-registers.store');
        Route::get('/cash-registers/{cashRegisterUuid}', [CashRegisterController::class, 'show'])->name('cash-registers.show');
        Route::put('/cash-registers/{cashRegisterUuid}', [CashRegisterController::class, 'update'])->name('cash-registers.update');
        Route::post('/cash-registers/{cashRegisterUuid}/session/lock', [CashRegisterController::class, 'lock'])->name('cash-registers.session.lock');
        Route::post('/cash-registers/{cashRegisterUuid}/session/unlock', [CashRegisterController::class, 'unlock'])->name('cash-registers.session.unlock');
        Route::post('/cash-registers/{cashRegisterUuid}/session/close', [CashRegisterController::class, 'close'])->name('cash-registers.session.close');
        Route::post('/cash-registers/{cashRegisterUuid}/activate', [CashRegisterController::class, 'activate'])->name('cash-registers.activate');
        Route::post('/cash-registers/{cashRegisterUuid}/deactivate', [CashRegisterController::class, 'deactivate'])->name('cash-registers.deactivate');
        Route::delete('/cash-registers/{cashRegisterUuid}', [CashRegisterController::class, 'destroy'])->name('cash-registers.destroy');
        Route::post('/cash-registers/{cashRegisterUuid}/restore', [CashRegisterController::class, 'restore'])->name('cash-registers.restore');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/bulk/deactivate', [UserController::class, 'bulkDeactivate'])->name('users.bulk.deactivate');
        Route::post('/users/bulk/force-delete', [UserController::class, 'bulkForceDelete'])->name('users.bulk.force_delete');
        Route::put('/users/{userUuid}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{userUuid}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('/users/{userUuid}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::delete('/users/{userUuid}', [UserController::class, 'forceDelete'])->name('users.force_delete');
        Route::put('/roles/{roleCode}/permissions', [UserController::class, 'updateRolePermissions'])->name('roles.permissions.update');

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
