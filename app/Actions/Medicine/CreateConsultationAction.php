<?php

namespace App\Actions\Medicine;

use App\Models\Consultation;
use App\Models\Episode;
use Illuminate\Support\Facades\Auth;

class CreateConsultationAction
{
    /**
     * @param  array{doctor_id?: int, reason: string, clinical_exam?: ?string, decision?: ?string, decision_notes?: ?string, consulted_at?: ?string}  $data
     */
    public function execute(Episode $episode, array $data): Consultation
    {
        $episode->startCare();

        return $episode->consultations()->create([
            'doctor_id' => $data['doctor_id'] ?? Auth::id(),
            'reason' => $data['reason'],
            'clinical_exam' => $data['clinical_exam'] ?? null,
            'decision' => $data['decision'] ?? null,
            'decision_notes' => $data['decision_notes'] ?? null,
            'consulted_at' => $data['consulted_at'] ?? now(),
        ]);
    }
}
