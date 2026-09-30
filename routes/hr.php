<?php

use App\Http\Controllers\Administration\AdvantageEntryController;
use App\Http\Controllers\Administration\BonusController;
use App\Http\Controllers\Administration\PayrollController;
use App\Http\Controllers\Administration\StaffDebtController;
use App\Http\Controllers\Administration\AttendanceController;
use App\Http\Controllers\Administration\BankController;
use App\Http\Controllers\Administration\EmployeeBadgeController;
use App\Http\Controllers\Administration\EmployeeBenefitController;
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
use App\Http\Controllers\Administration\StaffAccessController;
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
// ADR-209 — la planche des badges de la liste affichée (ou cochée) : sous sa liste, avant /employees/{employee}.
Route::get('/employees/badges', [EmployeeBadgeController::class, 'sheet'])->name('employees.badges')->middleware('can:employees.print')->defaults('scope', 'employees');
Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show')->middleware('can:employees.view')->withTrashed();
Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit')->middleware('can:employees.update');
// ADR-190 — l'adresse email professionnelle : le RH la demande, le Super Admin la crée depuis le portail.
Route::post('/employees/{employee}/professional-mailbox', [ProfessionalMailboxController::class, 'store'])->name('employees.professional-mailbox.store')->middleware('can:professional_emails.request');
Route::post('/professional-mailboxes/{mailbox}/cancel', [ProfessionalMailboxController::class, 'cancel'])->name('professional-mailboxes.cancel')->middleware('can:professional_emails.request');
// ADR-197 / ADR-202 — les accès créés par le Super Admin, annoncés aux employés par le RH ;
// chacun choisit son mot de passe à sa première connexion.
Route::get('/staff-access', [StaffAccessController::class, 'index'])->name('staff-access.index')->middleware('can:staff_access.receive');
Route::get('/staff-access/{handover}', [StaffAccessController::class, 'show'])->name('staff-access.show')->middleware('can:staff_access.receive');
Route::post('/staff-access/{handover}/items/{item}/reopen', [StaffAccessController::class, 'reopen'])->name('staff-access.reopen')->middleware('can:staff_access.receive');
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
// ADR-209 — le badge d'une personne (jamais d'un dossier archivé), et l'emblème déposé pour le badge du site.
Route::get('/employees/{employee}/badge', [EmployeeBadgeController::class, 'show'])->name('employees.badge')->middleware('can:employees.print');
Route::get('/badges/emblem', [EmployeeBadgeController::class, 'emblem'])->name('badges.emblem')->middleware('can:view-employee-badge');
// ADR-194 — la photo d'identité 4 × 4, lue sur le disque privé du site.
Route::get('/employees/{employee}/photo', [EmployeeController::class, 'photo'])->name('employees.photo')->middleware('can:view-employee-photo')->withTrashed();
Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update')->middleware('can:employees.update');
Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->name('employees.destroy')->middleware('can:employees.delete');
Route::post('/employees/{employee}/restore', [EmployeeController::class, 'restore'])->name('employees.restore')->middleware('can:employees.restore')->withTrashed();
// ADR-221 — les avantages et primes d'un employé : mêmes droits que sa rémunération.
Route::post('/employees/{employee}/benefits', [EmployeeBenefitController::class, 'store'])->name('employees.benefits.store')->middleware(['can:employees.update', 'can:employees.payroll.update']);
Route::put('/employees/{employee}/benefits/{benefit}', [EmployeeBenefitController::class, 'update'])->name('employees.benefits.update')->middleware(['can:employees.update', 'can:employees.payroll.update']);
Route::delete('/employees/{employee}/benefits/{benefit}', [EmployeeBenefitController::class, 'destroy'])->name('employees.benefits.destroy')->middleware(['can:employees.update', 'can:employees.payroll.update']);

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
Route::get('/internships/badges', [EmployeeBadgeController::class, 'sheet'])->name('internships.badges')->middleware('can:employees.print')->defaults('scope', 'interns');

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
// ADR-208 — un document archivé reste consultable ; « supprimer » l'archive, avec un motif.
Route::get('/generated-documents/{generatedDocument}/print', [GeneratedDocumentController::class, 'print'])->name('generated-documents.print')->middleware('can:generated_documents.print')->withTrashed();
Route::delete('/generated-documents/{generatedDocument}', [GeneratedDocumentController::class, 'destroy'])->name('generated-documents.destroy')->middleware('can:generated_documents.archive');
Route::post('/generated-documents/{generatedDocument}/restore', [GeneratedDocumentController::class, 'restore'])->name('generated-documents.restore')->middleware('can:generated_documents.restore')->withTrashed();

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

// ADR-212 — les bonus du personnel : le tableau du mois, les catégories, les attributions.
Route::get('/bonus', [BonusController::class, 'index'])->name('bonus.index')->middleware('can:bonus_awards.view');
Route::post('/bonus/categories', [BonusController::class, 'storeCategory'])->name('bonus.categories.store')->middleware('can:bonus_categories.create');
Route::put('/bonus/categories/{category}', [BonusController::class, 'updateCategory'])->name('bonus.categories.update')->middleware('can:bonus_categories.update');
Route::delete('/bonus/categories/{category}', [BonusController::class, 'destroyCategory'])->name('bonus.categories.destroy')->middleware('can:bonus_categories.archive');
Route::post('/bonus/categories/{category}/restore', [BonusController::class, 'restoreCategory'])->name('bonus.categories.restore')->middleware('can:bonus_categories.restore')->withTrashed();
Route::post('/bonus/awards', [BonusController::class, 'validateAward'])->name('bonus.awards.store')->middleware('can:bonus_awards.validate');
Route::post('/bonus/awards/{award}/pay', [BonusController::class, 'payAward'])->name('bonus.awards.pay')->middleware('can:bonus_awards.pay');
Route::post('/bonus/awards/{award}/cancel', [BonusController::class, 'cancelAward'])->name('bonus.awards.cancel')->middleware('can:bonus_awards.cancel');
// Avantages à l'acte : articles (quantité × prix unitaire) et avantages du mois, mêmes droits que les bonus.
Route::post('/bonus/avantages/articles', [BonusController::class, 'storeArticle'])->name('bonus.advantages.articles.store')->middleware('can:bonus_categories.create');
Route::put('/bonus/avantages/articles/{article}', [BonusController::class, 'updateArticle'])->name('bonus.advantages.articles.update')->middleware('can:bonus_categories.update');
Route::delete('/bonus/avantages/articles/{article}', [BonusController::class, 'destroyArticle'])->name('bonus.advantages.articles.destroy')->middleware('can:bonus_categories.archive');
Route::post('/bonus/avantages/articles/{article}/restore', [BonusController::class, 'restoreArticle'])->name('bonus.advantages.articles.restore')->middleware('can:bonus_categories.restore')->withTrashed();
Route::post('/bonus/avantages/awards', [BonusController::class, 'validateAdvantage'])->name('bonus.advantages.awards.store')->middleware('can:bonus_awards.validate');
Route::post('/bonus/avantages/awards/{award}/pay', [BonusController::class, 'payAdvantage'])->name('bonus.advantages.awards.pay')->middleware('can:bonus_awards.pay');
Route::post('/bonus/avantages/awards/{award}/cancel', [BonusController::class, 'cancelAdvantage'])->name('bonus.advantages.awards.cancel')->middleware('can:bonus_awards.cancel');
// ADR-227 — avantages saisis pour les médecins, et paie du mois qui les porte.
Route::post('/bonus/avantages/saisis', [AdvantageEntryController::class, 'store'])->name('bonus.advantages.entries.store')->middleware('can:advantage_entries.create');
Route::put('/bonus/avantages/saisis/{entry}', [AdvantageEntryController::class, 'update'])->name('bonus.advantages.entries.update')->middleware('can:advantage_entries.update');
Route::delete('/bonus/avantages/saisis/{entry}', [AdvantageEntryController::class, 'destroy'])->name('bonus.advantages.entries.destroy')->middleware('can:advantage_entries.delete');
Route::get('/paie', [PayrollController::class, 'index'])->name('payroll.index')->middleware('can:salary_payments.view');
Route::post('/paie/payer', [PayrollController::class, 'pay'])->name('payroll.pay')->middleware('can:salary_payments.pay');
Route::post('/paie/{payment}/annuler', [PayrollController::class, 'cancel'])->name('payroll.cancel')->middleware('can:salary_payments.cancel');
// ADR-228 — les dettes du personnel : le RH suit et marque versé, le DG (portail) décide.
Route::get('/dettes', [StaffDebtController::class, 'index'])->name('staff-debts.index')->middleware('can:staff_debts.view');
Route::get('/dettes/{staffDebt}', [StaffDebtController::class, 'show'])->name('staff-debts.show')->middleware('can:staff_debts.view');
Route::post('/dettes/{staffDebt}/accorder', [StaffDebtController::class, 'approve'])->name('staff-debts.approve')->middleware('can:staff_debts.decide');
Route::post('/dettes/{staffDebt}/refuser', [StaffDebtController::class, 'refuse'])->name('staff-debts.refuse')->middleware('can:staff_debts.decide');
Route::post('/dettes/{staffDebt}/ajuster', [StaffDebtController::class, 'adjust'])->name('staff-debts.adjust')->middleware('can:staff_debts.decide');
Route::post('/dettes/{staffDebt}/annuler', [StaffDebtController::class, 'cancel'])->name('staff-debts.cancel')->middleware('can:staff_debts.decide');
Route::post('/dettes/{staffDebt}/remettre', [StaffDebtController::class, 'writeOff'])->name('staff-debts.write-off')->middleware('can:staff_debts.write_off');
Route::post('/dettes/{staffDebt}/verser', [StaffDebtController::class, 'disburse'])->name('staff-debts.disburse')->middleware('can:staff_debts.disburse');

Route::get('/staff-block-credits', [StaffBlockCreditController::class, 'index'])
    ->name('staff-block-credits.index')
    ->middleware('can:staff_block_credits.view');
Route::post('/staff-block-credits/{employee}', [StaffBlockCreditController::class, 'store'])
    ->name('staff-block-credits.store')
    ->middleware('can:staff_block_credits.allocate');

// ADR-221 — le référentiel des banques du site, proposé à la fiche d'un employé.
Route::get('/banks', [BankController::class, 'index'])->name('banks.index')->middleware('can:hr_settings.view');
Route::post('/banks', [BankController::class, 'store'])->name('banks.store')->middleware('can:hr_settings.create');
Route::put('/banks/{bank}', [BankController::class, 'update'])->name('banks.update')->middleware('can:hr_settings.update');
Route::delete('/banks/{bank}', [BankController::class, 'destroy'])->name('banks.destroy')->middleware('can:hr_settings.archive');
Route::post('/banks/{bank}/restore', [BankController::class, 'restore'])->name('banks.restore')->middleware('can:hr_settings.restore')->withTrashed();
