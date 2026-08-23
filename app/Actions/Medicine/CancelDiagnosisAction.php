<?php

namespace App\Actions\Medicine;

use App\Models\Diagnosis;
use App\Models\DiagnosisCancellation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelDiagnosisAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(
        EpisodeOrientation $orientation,
        Diagnosis $diagnosis,
        User $actor,
    ): DiagnosisCancellation {
        return DB::transaction(function () use ($orientation, $diagnosis, $actor): DiagnosisCancellation {
            $lockedDiagnosis = Diagnosis::query()
                ->with(['consultation', 'cancellation'])
                ->lockForUpdate()
                ->findOrFail($diagnosis->getKey());

            if ($lockedDiagnosis->consultation?->episode_orientation_id !== $orientation->getKey()) {
                throw ValidationException::withMessages([
                    'diagnosis_id' => 'Ce diagnostic n’appartient pas à cette consultation.',
                ]);
            }

            if ($lockedDiagnosis->cancellation) {
                throw ValidationException::withMessages([
                    'diagnosis_id' => 'Ce diagnostic est déjà annulé.',
                ]);
            }

            if ($lockedDiagnosis->recorded_by !== $actor->getKey()) {
                throw ValidationException::withMessages([
                    'diagnosis_id' => 'Seul l’auteur de ce diagnostic peut l’annuler.',
                ]);
            }

            $reason = 'Annulation par l’auteur de la saisie.';

            $cancellation = DiagnosisCancellation::query()->create([
                'diagnosis_id' => $lockedDiagnosis->getKey(),
                'reason' => trim($reason),
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
            ]);

            $this->auditor->record(
                'cancel',
                entity: $lockedDiagnosis,
                newValues: ['cancellation_uuid' => $cancellation->uuid],
                reason: $cancellation->reason,
                module: 'medical',
                actor: $actor,
            );

            return $cancellation->load('cancelledBy:id,name');
        });
    }
}
