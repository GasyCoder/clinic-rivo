<?php

namespace App\Actions\Medicine;

use App\Enums\DiagnosisType;
use App\Models\Diagnosis;
use App\Models\DiagnosisCancellation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectDiagnosisAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(
        EpisodeOrientation $orientation,
        Diagnosis $diagnosis,
        DiagnosisType $type,
        string $description,
        User $actor,
    ): Diagnosis {
        return DB::transaction(function () use ($orientation, $diagnosis, $type, $description, $actor): Diagnosis {
            $original = Diagnosis::query()
                ->with(['consultation', 'cancellation'])
                ->lockForUpdate()
                ->findOrFail($diagnosis->getKey());

            if ($original->consultation?->episode_orientation_id !== $orientation->getKey()) {
                throw ValidationException::withMessages([
                    'diagnosis_id' => 'Ce diagnostic n’appartient pas à cette consultation.',
                ]);
            }

            if ($original->cancellation) {
                throw ValidationException::withMessages([
                    'diagnosis_id' => 'Un diagnostic annulé ou rectifié ne peut plus être modifié.',
                ]);
            }

            if ($original->recorded_by !== $actor->getKey()) {
                throw ValidationException::withMessages([
                    'diagnosis_id' => 'Seul l’auteur de ce diagnostic peut le modifier.',
                ]);
            }

            $replacement = Diagnosis::query()->create([
                'consultation_id' => $original->consultation_id,
                'type' => $type,
                'description' => trim($description),
                'notes' => $original->notes,
                'is_manual' => true,
                'recorded_by' => $actor->getKey(),
            ]);

            $cancellation = DiagnosisCancellation::query()->create([
                'diagnosis_id' => $original->getKey(),
                'replacement_diagnosis_id' => $replacement->getKey(),
                'reason' => 'Rectification par l’auteur de la saisie.',
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
            ]);

            $this->auditor->record(
                'update',
                entity: $original,
                oldValues: [
                    'type' => $original->type->value,
                    'description' => $original->description,
                ],
                newValues: [
                    'replacement_diagnosis_id' => $replacement->getKey(),
                    'type' => $replacement->type->value,
                    'description' => $replacement->description,
                    'cancellation_uuid' => $cancellation->uuid,
                ],
                reason: $cancellation->reason,
                module: 'medical',
                actor: $actor,
            );

            return $replacement;
        });
    }
}
