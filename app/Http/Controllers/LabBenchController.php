<?php

namespace App\Http\Controllers;

use App\Actions\Laboratory\BulkLabRequestAction;
use App\Actions\Laboratory\TrashLabRequestAction;
use App\Models\Patient;
use App\Services\Laboratory\LabPatientHistory;
use App\Services\Laboratory\LabRequestPresenter;
use App\Services\Laboratory\LabWorklist;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ADR-214 — deux lectures de la paillasse : la feuille de travail par
 * discipline, et l'historique des résultats d'un patient.
 */
class LabBenchController extends Controller
{
    public function worklist(Request $request, LabWorklist $worklist): Response
    {
        $discipline = str((string) $request->query('discipline'))->squish()->limit(120, '')->toString() ?: null;
        $data = $worklist->build($discipline);

        return Inertia::render('Laboratory/Worklist', [
            ...$data,
            'discipline' => $discipline,
            'printedAt' => now(),
            // ADR-220 — la sélection : imprimer une partie de la feuille, ou mettre à la corbeille.
            'manage' => [
                'trash' => $request->user()->can(TrashLabRequestAction::PERMISSION),
                'max' => BulkLabRequestAction::MAX,
            ],
        ]);
    }

    public function history(Request $request, Patient $patient, LabPatientHistory $history): Response
    {
        return Inertia::render('Laboratory/PatientHistory', [
            'patient' => LabRequestPresenter::patient($patient),
            ...$history->for($patient, $request->user()),
        ]);
    }
}
