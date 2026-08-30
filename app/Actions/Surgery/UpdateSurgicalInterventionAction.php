<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalIntervention;
use Illuminate\Validation\ValidationException;

class UpdateSurgicalInterventionAction
{
    /**
     * @param  array{ended_at?: ?string, procedure_summary?: ?string, notes?: ?string}  $data
     */
    public function execute(SurgicalIntervention $intervention, array $data): SurgicalIntervention
    {
        $intervention->loadMissing('surgicalRequest');

        if ($intervention->surgicalRequest->status !== SurgicalRequestStatus::InProgress) {
            throw ValidationException::withMessages([
                'intervention' => 'Une intervention clôturée ne peut plus être modifiée.',
            ]);
        }

        $intervention->fill($data);
        $intervention->save();

        return $intervention;
    }
}
