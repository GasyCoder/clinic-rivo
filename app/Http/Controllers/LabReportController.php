<?php

namespace App\Http\Controllers;

use App\Services\Audit\Auditor;
use App\Services\Laboratory\LabReports;
use App\Services\Spreadsheet\ExcelWorkbook;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ADR-214 — les rapports du Laboratoire et leur export Excel (CDC §14). */
class LabReportController extends Controller
{
    public function index(Request $request, LabReports $reports): Response
    {
        [$start, $end] = LabReports::period($request->query('du'), $request->query('au'));

        return Inertia::render('Laboratory/Reports', [
            'report' => $reports->build($start, $end),
            'canExport' => $request->user()->can('laboratory_reports.export'),
        ]);
    }

    public function export(Request $request, LabReports $reports, ExcelWorkbook $workbook, Auditor $auditor): StreamedResponse
    {
        [$start, $end] = LabReports::period($request->query('du'), $request->query('au'));

        $auditor->record('laboratory.reports.export', null, [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ], module: 'laboratory', actor: $request->user());

        return $workbook->download(
            "laboratoire-{$start->format('Y-m-d')}-{$end->format('Y-m-d')}.xlsx",
            'Laboratoire',
            LabReports::EXPORT_HEADERS,
            $reports->exportRows($start, $end),
        );
    }
}
