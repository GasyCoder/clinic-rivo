<?php

use App\Enums\ReceptionPatientStep;
use App\Http\Controllers\Administration\CatalogController as AdministrationCatalogController;
use App\Http\Controllers\Administration\UserController as AdministrationUserController;
use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\AnesthesiaController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CareController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\EpisodeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LogisticsController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientMutualCoverageAttachmentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PharmacyController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\Reception\EmployeePatientLookupController;
use App\Http\Controllers\Reception\EpisodeServiceController;
use App\Http\Controllers\ReceptionController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\SurgeryController;
use App\Http\Controllers\SurgicalCareNoteController;
use App\Http\Controllers\SurgicalComplicationController;
use App\Http\Controllers\SurgicalConsumableController;
use App\Http\Controllers\SurgicalInterventionController;
use App\Http\Controllers\SurgicalPreoperativeController;
use App\Http\Controllers\SurgicalReportController;
use App\Http\Controllers\SurgicalTeamMemberController;
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
        Route::get('/sites/{site}', [SuperAdminController::class, 'site'])->name('sites.show')->middleware('can:sites.view');
        Route::get('/workspaces/{workspace}', [SuperAdminController::class, 'workspace'])->name('workspaces.show');
    });

// Patients/Episodes are per-site clinical data (ADR-004: admin.rivo.mg never
// reaches a site's own data directly, only via API) — clinic-only, unlike
// auth which the gateway/admin deployments also use.
Route::middleware(['site.type:clinic', 'auth', 'account.active', 'account.deployment'])->group(function () {
    Route::get('/administration', AdministrationController::class)->name('administration.index')->middleware('can:employees.view');
    Route::get('/logistics', LogisticsController::class)->name('logistics.index')->middleware('can:logistics.view');
    Route::get('/pharmacy', PharmacyController::class)->name('pharmacy.index')->middleware('can:pharmacy.view');

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
    Route::delete('/administration/catalog/{catalogItem}', [AdministrationCatalogController::class, 'destroy'])->name('administration.catalog.destroy')->middleware('can:catalog.items.delete');
    Route::post('/administration/catalog/{catalogItem}/restore', [AdministrationCatalogController::class, 'restore'])->name('administration.catalog.restore')->middleware('can:catalog.items.restore');

    // Réception: one operational entry point, with isolated patient and
    // non-clinical visitor workflows. A visitor never creates an episode.
    Route::get('/reception', [ReceptionController::class, 'index'])->name('reception.index')->middleware('can:reception.view');
    Route::get('/reception/patients', [ReceptionController::class, 'patients'])->name('reception.patients.create')->middleware('can:episodes.create');
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
    Route::get('/reception/passages/{episode}/prestations', [EpisodeServiceController::class, 'show'])
        ->name('reception.passages.services.show')
        ->middleware('can:episodes.update');
    Route::post('/reception/passages/{episode}/prestations', [EpisodeServiceController::class, 'store'])
        ->name('reception.passages.services.store')
        ->middleware('can:episodes.update');
    Route::get('/reception/mutual-coverages/{coverage}/attachments/{attachment}', PatientMutualCoverageAttachmentController::class)
        ->name('reception.mutual-coverages.attachments.show')
        ->middleware('can:patient_coverage_documents.view');
    Route::get('/reception/visitors', [VisitorReceptionController::class, 'index'])->name('reception.visitors.index')->middleware('can:visitors.view');
    Route::post('/reception/visitors', [VisitorReceptionController::class, 'store'])->name('reception.visitors.store')->middleware('can:visitors.create');
    Route::get('/reception/visitors/{visitorVisit}/attachments/{attachment}', [VisitorReceptionController::class, 'professionalAttachment'])->name('reception.visitors.attachments.show')->middleware('can:visitors.view');
    Route::post('/reception/visitors/{visitorVisit}/close', [VisitorReceptionController::class, 'close'])->name('reception.visitors.close')->middleware('can:visitors.close');

    // Référentiel patients: administrative management, but no creation here.
    // Deletion is always audited Soft Delete through Patient::SoftDeletable.
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index')->middleware('can:patients.view');
    Route::post('/patients/bulk-delete', [PatientController::class, 'bulkDestroy'])->name('patients.bulk-destroy')->middleware('can:patients.delete');
    Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])->name('patients.edit')->middleware('can:patients.update');
    Route::put('/patients/{patient}', [PatientController::class, 'update'])->name('patients.update')->middleware('can:patients.update');
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy'])->name('patients.destroy')->middleware('can:patients.delete');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show')->middleware('can:patients.view');

    // Facturation / caisse : une seule caisse fonctionnelle par site. Les
    // prestations peuvent être facturées ici, mais seul ce module encaisse.
    Route::get('/cash', [CashController::class, 'index'])->name('cash.index')->middleware('can:cash.view');
    Route::post('/cash/open', [CashController::class, 'open'])->name('cash.open')->middleware('can:cash.open');
    Route::post('/cash/close', [CashController::class, 'close'])->name('cash.close')->middleware('can:cash.close');
    Route::post('/patients/{patient}/invoices', [BillingController::class, 'store'])->name('invoices.store')->middleware('can:billing.create');
    Route::get('/invoices/{invoice}', [BillingController::class, 'show'])->name('invoices.show')->middleware('can:billing.print');
    Route::post('/invoices/{invoice}/validate', [BillingController::class, 'validateInvoice'])->name('invoices.validate')->middleware('can:billing.validate');
    Route::post('/patients/{patient}/payments', [PaymentController::class, 'store'])->name('payments.store')->middleware('can:payments.create');
    Route::post('/payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel')->middleware('can:payments.cancel');
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show')->middleware('can:receipts.view');

    Route::post('/patients/{patient}/episodes', [EpisodeController::class, 'store'])->name('episodes.store')->middleware('can:episodes.create');

    // ADR-030 — operational queues are isolated by destination and opened
    // from the route configured on each selected designation. Unknown needs
    // start in Soins; an emergency receives both queues at admission.
    Route::get('/care', [CareController::class, 'index'])->name('care.index')->middleware('can:care.view');
    Route::post('/care/orientations/{episodeOrientation}/accept', [CareController::class, 'accept'])->name('care.orientations.accept')->middleware('can:care.update');
    Route::post('/care/orientations/{episodeOrientation}/complete', [CareController::class, 'complete'])->name('care.orientations.complete')->middleware('can:care.complete');
    Route::post('/care/orientations/{episodeOrientation}/complete-and-orient', [CareController::class, 'completeAndOrient'])->name('care.orientations.complete-and-orient')->middleware('can:care.complete');

    Route::get('/medicine', [MedicineController::class, 'index'])->name('medicine.index')->middleware('can:consultations.view');
    Route::post('/medicine/orientations/{episodeOrientation}/accept', [MedicineController::class, 'accept'])->name('medicine.orientations.accept')->middleware('can:consultations.create');

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
    Route::post('/surgery/{surgicalRequest}/discharge', [SurgeryController::class, 'discharge'])->name('surgery.discharge')->middleware('can:surgery.discharge.create');

    Route::post('/surgery/{surgicalRequest}/preoperative/validate', [SurgicalPreoperativeController::class, 'validatePreoperative'])->name('surgery.preoperative.validate')->middleware('can:surgery.preoperative.validate');

    Route::post('/surgery/{surgicalRequest}/team', [SurgicalTeamMemberController::class, 'store'])->name('surgery.team.store')->middleware('can:surgery.update');
    Route::delete('/surgery/{surgicalRequest}/team/{member}', [SurgicalTeamMemberController::class, 'destroy'])->name('surgery.team.destroy')->middleware('can:surgery.update');

    Route::post('/surgery/{surgicalRequest}/intervention', [SurgicalInterventionController::class, 'store'])->name('surgery.intervention.store')->middleware('can:surgery.intervention.create');
    Route::put('/surgery/{surgicalRequest}/intervention/{intervention}', [SurgicalInterventionController::class, 'update'])->name('surgery.intervention.update')->middleware('can:surgery.intervention.update');

    Route::post('/surgery/{surgicalRequest}/anesthesia', [AnesthesiaController::class, 'store'])->name('surgery.anesthesia.store')->middleware('can:anesthesia.create');
    Route::put('/surgery/{surgicalRequest}/anesthesia/{anesthesiaRecord}', [AnesthesiaController::class, 'update'])->name('surgery.anesthesia.update')->middleware('can:anesthesia.update');
    Route::post('/surgery/{surgicalRequest}/anesthesia/{anesthesiaRecord}/validate', [AnesthesiaController::class, 'validateRecord'])->name('surgery.anesthesia.validate')->middleware('can:anesthesia.validate');

    Route::post('/surgery/{surgicalRequest}/report', [SurgicalReportController::class, 'store'])->name('surgery.report.store')->middleware('can:surgery.report.create');
    Route::put('/surgery/{surgicalRequest}/report/{report}', [SurgicalReportController::class, 'update'])->name('surgery.report.update')->middleware('can:surgery.report.update');
    Route::post('/surgery/{surgicalRequest}/report/{report}/validate', [SurgicalReportController::class, 'validateReport'])->name('surgery.report.validate')->middleware('can:surgery.report.validate');

    Route::post('/surgery/{surgicalRequest}/complications', [SurgicalComplicationController::class, 'store'])->name('surgery.complications.store')->middleware('can:surgery.complications.create');
    Route::post('/surgery/{surgicalRequest}/consumables', [SurgicalConsumableController::class, 'store'])->name('surgery.consumables.store')->middleware('can:surgery.consumables.create');
    Route::delete('/surgery/{surgicalRequest}/consumables/{consumable}', [SurgicalConsumableController::class, 'destroy'])->name('surgery.consumables.destroy')->middleware('can:surgery.consumables.create');
    Route::post('/surgery/{surgicalRequest}/care-notes/perioperative', [SurgicalCareNoteController::class, 'storePerioperative'])->name('surgery.care-notes.perioperative')->middleware('can:surgery.care.create');
    Route::post('/surgery/{surgicalRequest}/care-notes/postoperative', [SurgicalCareNoteController::class, 'storePostoperative'])->name('surgery.care-notes.postoperative')->middleware('can:surgery.postoperative_care.create');
});
