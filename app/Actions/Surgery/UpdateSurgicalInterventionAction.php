<?php

namespace App\Actions\Surgery;

use App\Models\SurgicalIntervention;

class UpdateSurgicalInterventionAction
{
    /**
     * @param  array{ended_at?: ?string, procedure_summary?: ?string, notes?: ?string}  $data
     */
    public function execute(SurgicalIntervention $intervention, array $data): SurgicalIntervention
    {
        $intervention->fill($data);
        $intervention->save();

        return $intervention;
    }
}
