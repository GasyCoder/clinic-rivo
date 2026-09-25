<?php

use App\Http\Controllers\Administration\AttendanceController;
use App\Http\Controllers\Administration\EmployeeController;
use App\Http\Controllers\Administration\EmploymentContractController;
use App\Http\Controllers\Administration\GeneratedDocumentController;
use App\Http\Controllers\Administration\HrDocumentController;
use App\Http\Controllers\Administration\HrReferenceController;
use App\Http\Controllers\Administration\HrReportController;
use App\Http\Controllers\Administration\HrStructureController;
use App\Http\Controllers\Administration\InternshipController;
use App\Http\Controllers\Administration\LeaveController;
use App\Http\Controllers\Administration\PlanningController;
use App\Http\Controllers\Administration\ProfessionalMailboxController;
use App\Http\Controllers\Administration\StaffBlockCreditController;
use App\Http\Controllers\AdministrationController;
use Illuminate\Support\Facades\Route;

/*
 * Les Ressources humaines d'un site (ADR-066), écrites une seule fois et
 * servies deux fois (ADR-187) :
 *
 *   /administration/...               l'espace RH du site, pour ses comptes
 *   /api/v1/super-admin/hr/...        le même espace, pour le Super Admin du
 *                                     portail, derrière le jeton du site
 *
 * Mêmes contrôleurs, mêmes droits (`can:`), mêmes actions : le portail ne
 * réécrit aucune règle RH, il appelle celles du site. Une route ajoutée ici
 * est donc disponible aux deux — c'est voulu.
 */

Route::get('/', AdministrationController::class)->name('index')->middleware('can:employees.view');

Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index')->middleware('can:employees.view');
Route::get('/employees/export', [EmployeeController::class, 'export'])->name('employees.export')->middleware('can:employees.export');
Route::get('/employees/import', [EmployeeController::class, 'importPage'])->name('employees.import-page')->middleware('can:employees.import');
Route::get('/employees/import-template', [EmployeeController::class, 'importTemplate'])->name('employees.import-template')->middleware('can:employees.import');
Route::post('/employees/import', [EmployeeController::class, 'import'])->name('employees.import')->middleware('can:employees.import');
Route::get('/employees/create', [EmployeeController::class, 'create'])->name('employees.create')->middleware('can:employees.create');
Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store')->middleware('can:employees.create');
Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show')->middleware('can:employees.view')->withTrashed();
Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->middleware('can:employees.update');
// ADR-190 — l'adresse email professionnelle : le RH la demande, le Super Admin la crée depuis le portail.
Route::post('/employees/{employee}/professional-mailbox', [ProfessionalMailboxController::class, 'store'])->name('employees.professional-mailbox.store')->middleware('can:professional_emails.request');
Route::post('/professional-mailboxes/{mailbox}/cancel', [ProfessionalMailboxController::class, 'cancel'])->name('professional-mailboxes.cancel')->middleware('can:professional_emails.request');
// ADR-190 (amendement du 2026-09-25) — la page RH des adresses du site. Créer, refuser, suspendre,
// réactiver et renouveler le mot de passe suivent le droit accordé par le Super Admin.
Route::get('/professional-emails', [ProfessionalMailboxController::class, 'index'])->name('professional-emails.index')->middleware('can:professional_emails.view');
Route::post('/professional-emails/check', [ProfessionalMailboxController::class, 'check'])->name('professional-emails.check')->middleware('can:professional_emails.create');
Route::post('/professional-emails/prepare', [ProfessionalMailboxController::class, 'prepare'])->name('professional-emails.prepare');
Route::post('/professional-emails/direct', [ProfessionalMailboxController::class, 'direct'])->name('professional-emails.direct')->middleware('can:professional_emails.create');
Route::post('/professional-emails/{mailbox}/create', [ProfessionalMailboxController::class, 'create'])->name('professional-emails.create')->middleware('can:professional_emails.create');
Route::post('/professional-emails/{mailbox}/reject', [ProfessionalMailboxController::class, 'reject'])->name('professional-emails.reject')->middleware('can:professional_emails.reject');
Route::post('/professional-emails/{mailbox}/suspend', [ProfessionalMailboxController::class, 'suspend'])->name('professional-emails.suspend')->middleware('can:professional_emails.deactivate');
Route::post('/professional-emails/{mailbox}/reactivate', [ProfessionalMailboxController::class, 'reactivate'])->name('professional-emails.reactivate')->middleware('can:professional_emails.activate');
Route::post('/professional-emails/{mailbox}/password', [ProfessionalMailboxController::class, 'resetPassword'])->name('professional-emails.password')->middleware('can:professional_emails.update');
Route::get('/employees/{employee}/print', [EmployeeController::class, 'print'])->name('employees.print')->middleware('can:employees.print')->withTrashed();
// ADR-194 — la photo d'identité 4 × 4, lue sur le disque privé du site.
Route::get('/employees/{employee}/photo', [EmployeeController::class, 'photo'])->name('employees.photo')->middleware('can:view-employee-photo')->withTrashed();
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

// ADR-194 — les stages : les contrats de stage, lus avec leur filière.
Route::get('/internships', [InternshipController::class, 'index'])->name('internships.index')->middleware('can:contracts.view');

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

// ADR-188 — Départements et Fonctions, chacun son module : même référentiel et
// mêmes droits que les Paramètres RH, le type se lisant sur l'adresse.
foreach (['departments', 'job-titles'] as $structure) {
    Route::prefix($structure)->name("{$structure}.")->controller(HrStructureController::class)->group(function (): void {
        Route::get('/', 'index')->name('index')->middleware('can:hr_settings.view');
        Route::post('/', 'store')->name('store')->middleware('can:hr_settings.create');
        Route::put('/{reference}', 'update')->name('update')->middleware('can:hr_settings.update');
        Route::delete('/{reference}', 'destroy')->name('destroy')->middleware('can:hr_settings.archive');
        Route::post('/{reference}/restore', 'restore')->name('restore')->middleware('can:hr_settings.restore')->withTrashed();
    });
}

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

Route::get('/staff-block-credits', [StaffBlockCreditController::class, 'index'])
    ->name('staff-block-credits.index')
    ->middleware('can:staff_block_credits.view');
Route::post('/staff-block-credits/{employee}', [StaffBlockCreditController::class, 'store'])
    ->name('staff-block-credits.store')
    ->middleware('can:staff_block_credits.allocate');
