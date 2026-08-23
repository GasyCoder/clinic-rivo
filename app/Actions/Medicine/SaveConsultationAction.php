<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveConsultationAction
{
    /**
     * @param  array{reason: string, clinical_exam?: ?string, decision?: ?string, decision_notes?: ?string}  $data
     */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): Consultation
    {
        return DB::transaction(function () use ($orientation, $data): Consultation {
            $locked = EpisodeOrientation::query()
                ->with('consultation')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Medicine
                || $locked->status !== EpisodeOrientationStatus::InProgress
                || ! $locked->consultation) {
                throw ValidationException::withMessages([
                    'consultation' => 'Cette consultation Médecine n’est pas modifiable.',
                ]);
            }

            if ($locked->episode->medicalDischarge()->exists()) {
                throw ValidationException::withMessages([
                    'consultation' => 'La sortie médicale est déjà prononcée ; cette consultation est désormais en lecture seule.',
                ]);
            }

            $locked->consultation->update([
                'reason' => $data['reason'],
                'clinical_exam' => $data['clinical_exam'] ?? null,
                'decision' => $data['decision'] ?? null,
                'decision_notes' => $data['decision_notes'] ?? null,
            ]);

            return $locked->consultation->fresh();
        });
    }
}
