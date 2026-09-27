<?php

use App\Http\Controllers\Partners\PartnerController;
use Illuminate\Support\Facades\Route;

/*
 * ADR-211 — le module Partenaires, écrit une seule fois.
 *
 * Monté sur le site sous `/partenaires` (routes/web.php) et, pour le portail,
 * sous `/api/v1/super-admin/site-partners` (routes/api.php) : les mêmes
 * contrôleurs et les mêmes droits, où que le module soit ouvert.
 */

Route::get('/', [PartnerController::class, 'index'])->name('index')->middleware('can:partner_organizations.view');
Route::post('/', [PartnerController::class, 'store'])->name('store')->middleware('can:partner_organizations.create');
Route::put('/{partner}', [PartnerController::class, 'update'])->name('update')->middleware('can:partner_organizations.update');
Route::delete('/{partner}', [PartnerController::class, 'destroy'])->name('destroy')->middleware('can:partner_organizations.archive');
Route::post('/{partner}/restore', [PartnerController::class, 'restore'])->withTrashed()->name('restore')->middleware('can:partner_organizations.restore');
