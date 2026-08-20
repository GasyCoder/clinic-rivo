<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalReport;

class UpdateSurgicalReportAction
{
    public function execute(SurgicalReport $report, string $content): SurgicalReport
    {
        $report->content = $content;
        $report->save();

        return $report;
    }
}
