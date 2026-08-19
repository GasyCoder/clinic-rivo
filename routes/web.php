<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ReceptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('dashboard');

Route::middleware(['site.type:clinic,admin', 'guest'])->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware(['site.type:clinic,admin', 'auth'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Patients/Episodes are per-site clinical data (ADR-004: admin.rivo.mg never
// reaches a site's own data directly, only via API) — clinic-only, unlike
// auth which the gateway/admin deployments also use.
Route::middleware(['site.type:clinic', 'auth'])->group(function () {
    // Réception & Caisse (CDC §5.2.1): the only place a patient/passage is
    // created — see RegisterArrivalAction for why these aren't split.
    Route::get('/reception', [ReceptionController::class, 'create'])->name('reception.create')->middleware('can:episodes.create');
    Route::post('/reception', [ReceptionController::class, 'store'])->name('reception.store')->middleware('can:episodes.create');

    // Référentiel patients: read-only directory, no creation here.
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index')->middleware('can:patients.view');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show')->middleware('can:patients.view');

    Route::post('/patients/{patient}/episodes', [EpisodeController::class, 'store'])->name('episodes.store')->middleware('can:episodes.create');
    Route::post('/episodes/{episode}/orient', [EpisodeController::class, 'orient'])->name('episodes.orient')->middleware('can:episodes.update');
});
