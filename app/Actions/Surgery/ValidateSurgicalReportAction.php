<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalReport;
use App\Models\SurgicalRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateSurgicalReportAction
{
    /**
     * Validating the report also completes the parent case (IN_PROGRESS →
     * COMPLETED) — a validated operative report is the evidence the
     * intervention is clinically finished, same reasoning as
     * Episode::startCare() being triggered by a consultation happening.
     *
     * The case's own transition is checked BEFORE the report is touched, in
     * one transaction: validating a report on a case that is not at the block
     * used to save the report as validated and then fail on complete(),
     * leaving a report nobody could correct on a case nobody could close.
     */
    public function execute(SurgicalReport $report): SurgicalReport
    {
        return DB::transaction(function () use ($report): SurgicalReport {
            $report = SurgicalReport::query()->lockForUpdate()->findOrFail($report->getKey());

            if ($report->validated_at !== null) {
                throw ValidationException::withMessages([
                    'report' => 'Ce compte rendu opératoire est déjà validé.',
                ]);
            }

            $surgicalRequest = SurgicalRequest::query()->lockForUpdate()->findOrFail($report->surgical_request_id);

            if ($surgicalRequest->status !== SurgicalRequestStatus::InProgress) {
                throw ValidationException::withMessages([
                    'report' => 'Le compte rendu se valide une fois l’intervention démarrée : sa validation clôt l’intervention.',
                ]);
            }

            $report->validated_by = Auth::id();
            $report->validated_at = now();
            $report->save();

            $surgicalRequest->complete();

            return $report->setRelation('surgicalRequest', $surgicalRequest);
        });
    }
}
