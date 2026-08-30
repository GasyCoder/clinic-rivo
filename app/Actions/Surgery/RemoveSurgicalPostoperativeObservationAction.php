<?php

namespace App\Actions\Surgery;

use App\Enums\SurgicalRequestStatus;
use App\Models\SurgicalPostoperativeObservation;
use App\Services\Audit\Auditor;
use Illuminate\Validation\ValidationException;

class RemoveSurgicalPostoperativeObservationAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(SurgicalPostoperativeObservation $observation): void
    {
        $observation->loadMissing('surgicalRequest');

        if ($observation->surgicalRequest->status === SurgicalRequestStatus::Discharged) {
            throw ValidationException::withMessages([
                'observation' => 'Une constante postopératoire ne peut plus être retirée après la sortie.',
            ]);
        }

        $this->auditor->record('delete', entity: $observation, module: 'surgery');
        $observation->delete();
    }
}
