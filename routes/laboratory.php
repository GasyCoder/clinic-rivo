<?php

use App\Http\Controllers\LabBenchController;
use App\Http\Controllers\LabMicrobiologyController;
use App\Http\Controllers\LaboratoryController;
use App\Http\Controllers\LabReceptionController;
use App\Http\Controllers\LabRequestManagementController;
use App\Http\Controllers\LabReportController;
use App\Http\Controllers\LabResultPdfController;
use App\Http\Controllers\LabSampleTypeController;
use Illuminate\Support\Facades\Route;

/*
 * ADR-213 / ADR-214 — le Laboratoire, écrit une seule fois.
 *
 * Monté sur le site sous `/laboratory` (routes/web.php) et, pour le portail,
 * sous `/api/v1/super-admin/site-laboratory` (routes/api.php) : les mêmes
 * contrôleurs et les mêmes droits, où que l'écran soit ouvert (ADR-215).
 *
 * Les gestes cliniques — réceptionner, prélever, saisir, envoyer au médecin,
 * renvoyer, signaler un critique, confier à l'extérieur, conclure — portent
 * `rivo.site-only:laboratory` : ils se font au laboratoire du site, par la
 * personne qui a le tube sous les yeux, jamais depuis le portail. Le portail
 * consulte tout et gère les référentiels (prélèvements et tubes, microbiologie).
 */

Route::get('/', [LaboratoryController::class, 'index'])->name('index')->middleware('can:laboratory_results.view');
Route::get('/requests/{labRequest}', [LaboratoryController::class, 'show'])->name('requests.show')->middleware('can:laboratory_results.view');
// ADR-218 — le compte rendu en PDF, produit par le serveur.
Route::get('/requests/{labRequest}/resultats.pdf', [LabResultPdfController::class, 'laboratory'])->name('requests.pdf')->middleware('can:laboratory_results.view');
Route::get('/requests/{labRequest}/impression', [LaboratoryController::class, 'print'])->name('requests.print')->middleware('can:laboratory_results.view');
// ADR-216 — envoyer au médecin valide le résultat : il n'y a plus de biologiste distinct.
Route::post('/requests/{labRequest}/send', [LaboratoryController::class, 'send'])->name('requests.send')->middleware(['can:laboratory_results.validate', 'rivo.site-only:laboratory']);
Route::put('/items/{labRequestItem}/results', [LaboratoryController::class, 'saveResults'])->name('items.results')->middleware(['can:laboratory_results.create', 'rivo.site-only:laboratory']);
// ADR-219 — remettre une saisie à zéro, tant qu'elle n'est pas envoyée au médecin.
Route::post('/items/{labRequestItem}/reset', [LaboratoryController::class, 'resetResults'])->name('items.reset')->middleware(['can:laboratory_results.create', 'rivo.site-only:laboratory']);
Route::put('/items/{labRequestItem}/antibiograms/{labAntibiogram}', [LaboratoryController::class, 'saveAntibiogram'])->name('items.antibiograms.update')->middleware(['can:laboratory_results.create', 'rivo.site-only:laboratory']);
Route::post('/items/{labRequestItem}/return', [LaboratoryController::class, 'returnItem'])->name('items.return')->middleware(['can:laboratory_results.return', 'rivo.site-only:laboratory']);
Route::post('/results/{labResult}/critical', [LaboratoryController::class, 'flagCritical'])->name('results.critical')->middleware(['can:laboratory_results.flag_critical', 'rivo.site-only:laboratory']);
Route::post('/items/{labRequestItem}/result', [LaboratoryController::class, 'recordResult'])->name('items.result')->middleware(['can:laboratory_results.create', 'rivo.site-only:laboratory']);
Route::post('/requests/{labRequest}/receive', [LabReceptionController::class, 'receive'])->name('requests.receive')->middleware(['can:take-up-lab-request', 'rivo.site-only:laboratory']);
// ADR-217 — « Traiter » : la demande est prise en charge et la paillasse s'ouvre, sans attendre le règlement.
Route::post('/requests/{labRequest}/start', [LabReceptionController::class, 'start'])->name('requests.start')->middleware(['can:take-up-lab-request', 'rivo.site-only:laboratory']);
Route::post('/requests/{labRequest}/samples', [LabReceptionController::class, 'storeSamples'])->name('requests.samples')->middleware(['can:laboratory_samples.create', 'rivo.site-only:laboratory']);
Route::get('/requests/{labRequest}/etiquettes', [LabReceptionController::class, 'labels'])->name('requests.labels')->middleware('can:laboratory_results.view');
Route::get('/requests/{labRequest}/bon-envoi', [LabReceptionController::class, 'sendOutSlip'])->name('requests.send-out-slip')->middleware('can:laboratory_results.view');
Route::put('/requests/{labRequest}/conclusion', [LabReceptionController::class, 'conclusion'])->name('requests.conclusion')->middleware(['can:laboratory_results.validate', 'rivo.site-only:laboratory']);
Route::post('/samples/{labSample}/reject', [LabReceptionController::class, 'rejectSample'])->name('samples.reject')->middleware(['can:laboratory_samples.update', 'rivo.site-only:laboratory']);
Route::post('/items/{labRequestItem}/send-out', [LabReceptionController::class, 'sendOut'])->name('items.send-out')->middleware(['can:laboratory_orders.send_out', 'rivo.site-only:laboratory']);
Route::post('/items/{labRequestItem}/send-out/cancel', [LabReceptionController::class, 'cancelSendOut'])->name('items.send-out.cancel')->middleware(['can:laboratory_orders.send_out', 'rivo.site-only:laboratory']);
// ADR-220 — ranger, corriger, mettre à la corbeille ; une demande ou une sélection.
// Archiver et mettre à la corbeille se font aussi depuis le portail (aucun geste
// clinique) ; corriger une demande (analyses, renseignements) reste au site.
Route::post('/requests/bulk', [LabRequestManagementController::class, 'bulk'])->name('requests.bulk')->middleware('can:laboratory_results.view');
Route::post('/requests/{labRequest}/archive', [LabRequestManagementController::class, 'archive'])->name('requests.archive')->middleware('can:laboratory_orders.archive');
Route::post('/requests/{labRequest}/unarchive', [LabRequestManagementController::class, 'unarchive'])->name('requests.unarchive')->middleware('can:laboratory_orders.archive');
Route::delete('/requests/{labRequest}', [LabRequestManagementController::class, 'trash'])->name('requests.trash')->middleware('can:laboratory_orders.delete');
Route::put('/requests/{labRequest}/renseignements', [LabRequestManagementController::class, 'updateNotes'])->name('requests.notes')->middleware(['can:laboratory_orders.update', 'rivo.site-only:laboratory']);
Route::post('/requests/{labRequest}/items', [LabRequestManagementController::class, 'addItems'])->name('requests.items.add')->middleware(['can:laboratory_orders.update', 'rivo.site-only:laboratory']);
Route::delete('/items/{labRequestItem}', [LabRequestManagementController::class, 'removeItem'])->name('items.remove')->middleware(['can:laboratory_orders.update', 'rivo.site-only:laboratory']);
Route::get('/paillasse', [LabBenchController::class, 'worklist'])->name('worklist')->middleware('can:laboratory_results.view');
Route::get('/patients/{patient}/historique', [LabBenchController::class, 'history'])->name('patients.history')->middleware('can:laboratory_results.view');
Route::get('/rapports', [LabReportController::class, 'index'])->name('reports')->middleware('can:laboratory_reports.view');
Route::get('/rapports/export', [LabReportController::class, 'export'])->name('reports.export')->middleware('can:laboratory_reports.export');
Route::get('/prelevements', [LabSampleTypeController::class, 'index'])->name('sample-types.index')->middleware('can:lab_sample_types.view');
Route::post('/prelevements/referentiel-de-depart', [LabSampleTypeController::class, 'importStarter'])->name('sample-types.starter')->middleware('can:lab_sample_types.create');
Route::post('/prelevements/{kind}', [LabSampleTypeController::class, 'store'])->name('sample-types.store')->middleware('can:lab_sample_types.create')->whereIn('kind', ['sample', 'tube']);
Route::put('/prelevements/{kind}/{uuid}', [LabSampleTypeController::class, 'update'])->name('sample-types.update')->middleware('can:lab_sample_types.update')->whereIn('kind', ['sample', 'tube']);
Route::delete('/prelevements/{kind}/{uuid}', [LabSampleTypeController::class, 'archive'])->name('sample-types.archive')->middleware('can:lab_sample_types.archive')->whereIn('kind', ['sample', 'tube']);
Route::post('/prelevements/{kind}/{uuid}/restore', [LabSampleTypeController::class, 'restore'])->name('sample-types.restore')->middleware('can:lab_sample_types.restore')->whereIn('kind', ['sample', 'tube']);
Route::get('/microbiologie', [LabMicrobiologyController::class, 'index'])->name('microbiology.index')->middleware('can:lab_microbiology.view');
Route::post('/microbiologie/referentiel-de-depart', [LabMicrobiologyController::class, 'importStarter'])->name('microbiology.starter')->middleware('can:lab_microbiology.create');
Route::post('/microbiologie/{kind}', [LabMicrobiologyController::class, 'store'])->name('microbiology.store')->middleware('can:lab_microbiology.create')->whereIn('kind', ['family', 'bacterium', 'antibiotic']);
Route::put('/microbiologie/{kind}/{uuid}', [LabMicrobiologyController::class, 'update'])->name('microbiology.update')->middleware('can:lab_microbiology.update')->whereIn('kind', ['family', 'bacterium', 'antibiotic']);
Route::delete('/microbiologie/{kind}/{uuid}', [LabMicrobiologyController::class, 'archive'])->name('microbiology.archive')->middleware('can:lab_microbiology.archive')->whereIn('kind', ['family', 'bacterium', 'antibiotic']);
Route::post('/microbiologie/{kind}/{uuid}/restore', [LabMicrobiologyController::class, 'restore'])->name('microbiology.restore')->middleware('can:lab_microbiology.restore')->whereIn('kind', ['family', 'bacterium', 'antibiotic']);
