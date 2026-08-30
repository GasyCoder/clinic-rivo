<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalReport;
use Illuminate\Validation\ValidationException;

class UpdateSurgicalReportAction
{
    public function execute(SurgicalReport $report, string $content): SurgicalReport
    {
        if ($report->validated_at !== null) {
            throw ValidationException::withMessages([
                'content' => 'Le compte rendu validé ne peut plus être modifié.',
            ]);
        }

        $report->content = $content;
        $report->save();

        return $report;
    }
}
