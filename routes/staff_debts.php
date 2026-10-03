<?php

use App\Http\Controllers\Finance\StaffDebtController;
use Illuminate\Support\Facades\Route;

/*
 * ADR-229 — les dettes du personnel d'un site, gérées depuis le portail
 * (Finance › Dettes du personnel). Ces routes ne sont montées que sur l'API du
 * site (`/api/v1/super-admin/site-staff-debts`), derrière son jeton : le site
 * n'a plus d'écran de gestion. ADR-245 — le Super Admin crée la dette ; l'employé la suit dans « Mes dettes », la
 * Caisse encaisse, la paie retient ; le reste se décide et se verse au portail.
 */

Route::get('/', [StaffDebtController::class, 'index'])->name('index')->middleware('can:staff_debts.view');
Route::get('/export', [StaffDebtController::class, 'export'])->name('export')->middleware('can:staff_debts.export');
Route::get('/reglages', [StaffDebtController::class, 'settings'])->name('settings')->middleware('can:staff_debts.settings');
Route::put('/reglages', [StaffDebtController::class, 'updateSettings'])->name('settings.update')->middleware('can:staff_debts.settings');

// ADR-245 — le Super Admin crée la dette, puis la valide par le circuit habituel.
Route::get('/nouvelle', [StaffDebtController::class, 'create'])->name('create')->middleware('can:staff_debts.create');
Route::post('/', [StaffDebtController::class, 'store'])->name('store')->middleware('can:staff_debts.create');

Route::get('/{staffDebt}', [StaffDebtController::class, 'show'])->name('show')->middleware('can:staff_debts.view');
Route::post('/{staffDebt}/accorder', [StaffDebtController::class, 'approve'])->name('approve')->middleware('can:staff_debts.decide');
Route::post('/{staffDebt}/refuser', [StaffDebtController::class, 'refuse'])->name('refuse')->middleware('can:staff_debts.decide');
Route::post('/{staffDebt}/ajuster', [StaffDebtController::class, 'adjust'])->name('adjust')->middleware('can:staff_debts.decide');
Route::post('/{staffDebt}/annuler', [StaffDebtController::class, 'cancel'])->name('cancel')->middleware('can:staff_debts.decide');
Route::post('/{staffDebt}/remettre', [StaffDebtController::class, 'writeOff'])->name('write-off')->middleware('can:staff_debts.write_off');
Route::post('/{staffDebt}/verser', [StaffDebtController::class, 'disburse'])->name('disburse')->middleware('can:staff_debts.disburse');
Route::post('/{staffDebt}/relancer', [StaffDebtController::class, 'remind'])->name('remind')->middleware('can:staff_debts.decide');
// ADR-230 — pénalités de retard, règlement au départ, documents à signer.
Route::post('/{staffDebt}/penalites/{penalty}/remettre', [StaffDebtController::class, 'waivePenalty'])->name('penalties.waive')->middleware('can:staff_debts.write_off');
Route::post('/{staffDebt}/depart', [StaffDebtController::class, 'settleDeparture'])->name('departure.settle')->middleware('can:staff_debts.decide');
Route::get('/{staffDebt}/reconnaissance', [StaffDebtController::class, 'acknowledgement'])->name('acknowledgement')->middleware('can:staff_debts.view');
Route::get('/{staffDebt}/protocole-depart', [StaffDebtController::class, 'departureAgreement'])->name('departure.agreement')->middleware('can:staff_debts.view');
