<?php

namespace App\Http\Controllers;

use App\Actions\Surgery\CreateSurgicalReportAction;
use App\Actions\Surgery\UpdateSurgicalReportAction;
use App\Actions\Surgery\ValidateSurgicalReportAction;
use App\Http\Requests\StoreSurgicalReportRequest;
use App\Models\SurgicalReport;
use App\Models\SurgicalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SurgicalReportController extends Controller
{
    public function store(StoreSurgicalReportRequest $request, SurgicalRequest $surgicalRequest, CreateSurgicalReportAction $action): RedirectResponse
    {
        $action->execute($surgicalRequest, $request->validated('content'));

        return back()->with('status', 'Compte rendu opératoire créé.');
    }

    public function update(StoreSurgicalReportRequest $request, SurgicalRequest $surgicalRequest, SurgicalReport $report, UpdateSurgicalReportAction $action): RedirectResponse
    {
        abort_unless($report->surgical_request_id === $surgicalRequest->getKey(), 404);

        $action->execute($report, $request->validated('content'));

        return back()->with('status', 'Compte rendu opératoire mis à jour.');
    }

    public function validateReport(Request $request, SurgicalRequest $surgicalRequest, SurgicalReport $report, ValidateSurgicalReportAction $action): RedirectResponse
    {
        abort_unless($report->surgical_request_id === $surgicalRequest->getKey(), 404);

        $action->execute($report);

        return back()->with('status', 'Compte rendu opératoire validé — dossier clôturé.');
    }
}
