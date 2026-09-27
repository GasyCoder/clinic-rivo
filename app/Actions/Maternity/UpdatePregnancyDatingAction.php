<?php

namespace App\Actions\Maternity;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\PregnancyDatingMethod;
use App\Enums\PregnancyStatus;
use App\Models\EpisodeOrientation;
use App\Models\Pregnancy;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Maternity\PregnancyDatingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Correction explicite et auditée de la datation longitudinale. */
final class UpdatePregnancyDatingAction
{
    public function __construct(
        private readonly PregnancyDatingService $dating,
        private readonly Auditor $auditor,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): Pregnancy
    {
        return DB::transaction(function () use ($orientation, $data, $actor): Pregnancy {
            $locked = EpisodeOrientation::query()
                ->with('episode.maternityRecord')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Maternity
                || $locked->status !== EpisodeOrientationStatus::InProgress
                || $locked->episode->maternityRecord?->pregnancy_id === null) {
                throw ValidationException::withMessages(['pregnancy' => 'Aucune grossesse active n’est liée à cette consultation.']);
            }

            $pregnancy = Pregnancy::query()->lockForUpdate()->findOrFail($locked->episode->maternityRecord->pregnancy_id);

            if ($pregnancy->status !== PregnancyStatus::Ongoing) {
                throw ValidationException::withMessages(['pregnancy' => 'La datation d’une grossesse terminée ne se corrige pas depuis une nouvelle consultation.']);
            }

            $method = PregnancyDatingMethod::from($data['dating_method']);
            $lastPeriod = $data['last_menstrual_period'] ?? null;
            $dueDate = $method === PregnancyDatingMethod::LastMenstrualPeriod
                ? $this->dating->estimatedDueDate($lastPeriod)->toDateString()
                : $data['estimated_due_date'];

            $old = [
                'last_menstrual_period' => $pregnancy->last_menstrual_period?->toDateString(),
                'estimated_due_date' => $pregnancy->estimated_due_date?->toDateString(),
                'dating_method' => $pregnancy->dating_method?->value,
            ];

            $pregnancy->forceFill([
                'last_menstrual_period' => $lastPeriod,
                'estimated_due_date' => $dueDate,
                'dating_method' => $method,
                'dating_confirmed_at' => now(),
                'dating_confirmed_by' => $actor->getKey(),
                'dating_correction_reason' => $data['reason'] ?? null,
                'updated_by' => $actor->getKey(),
            ])->saveQuietly();

            $this->auditor->record(
                'maternity.pregnancy.dating.correct',
                entity: $pregnancy,
                oldValues: $old,
                newValues: [
                    'last_menstrual_period' => $pregnancy->last_menstrual_period?->toDateString(),
                    'estimated_due_date' => $pregnancy->estimated_due_date?->toDateString(),
                    'dating_method' => $pregnancy->dating_method?->value,
                    'dating_confirmed_at' => $pregnancy->dating_confirmed_at,
                    'dating_confirmed_by' => $actor->getKey(),
                ],
                reason: $data['reason'] ?? null,
                module: 'maternity',
                actor: $actor,
            );

            return $pregnancy->fresh();
        });
    }
}
