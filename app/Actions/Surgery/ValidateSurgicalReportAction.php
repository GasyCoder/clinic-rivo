<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ValidateSurgicalReportAction
{
    /**
     * Validating the report also completes the parent case (IN_PROGRESS →
     * COMPLETED) — a validated operative report is the evidence the
     * intervention is clinically finished, same reasoning as
     * Episode::startCare() being triggered by a consultation happening.
     */
    public function execute(SurgicalReport $report): SurgicalReport
    {
        if ($report->validated_at !== null) {
            throw ValidationException::withMessages([
                'report' => 'Ce compte rendu opératoire est déjà validé.',
            ]);
        }

        $report->validated_by = Auth::id();
        $report->validated_at = now();
        $report->save();

        $report->surgicalRequest->complete();

        return $report;
    }
}
