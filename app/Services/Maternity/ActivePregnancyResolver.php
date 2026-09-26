<?php

namespace App\Services\Maternity;

use App\Enums\PregnancyDatingMethod;
use App\Enums\PregnancyStatus;
use App\Models\EpisodeOrientation;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Résout explicitement la grossesse longitudinale d'une consultation.
 *
 * Le verrou sur la patiente sérialise deux créations concurrentes. Il n'y a
 * aucune heuristique fondée seulement sur « même patiente » : continuer ou
 * créer est toujours un choix envoyé par le workflow.
 */
final class ActivePregnancyResolver
{
    public function __construct(private readonly PregnancyDatingService $dating) {}

    /** @param array<string, mixed> $data */
    public function resolve(
        EpisodeOrientation $orientation,
        ?MaternityRecord $record,
        array $data,
        User $actor,
    ): Pregnancy {
        if ($record?->pregnancy_id !== null) {
            return Pregnancy::query()->lockForUpdate()->findOrFail($record->pregnancy_id);
        }

        $patient = Patient::query()->lockForUpdate()->findOrFail($orientation->episode->patient_id);
        $choice = $data['pregnancy_choice'] ?? null;

        if ($choice === 'CONTINUE') {
            $pregnancy = Pregnancy::query()
                ->where('uuid', $data['pregnancy_uuid'] ?? '')
                ->where('patient_id', $patient->getKey())
                ->lockForUpdate()
                ->first();

            if ($pregnancy === null || $pregnancy->status !== PregnancyStatus::Ongoing) {
                throw ValidationException::withMessages([
                    'pregnancy_uuid' => 'La grossesse choisie n’est plus active ou n’appartient pas à cette patiente.',
                ]);
            }

            return $pregnancy;
        }

        if ($choice !== 'CREATE') {
            throw ValidationException::withMessages([
                'pregnancy_choice' => 'Choisissez explicitement de continuer la grossesse active ou d’en créer une nouvelle.',
            ]);
        }

        $active = Pregnancy::query()
            ->where('patient_id', $patient->getKey())
            ->ongoing()
            ->lockForUpdate()
            ->first();

        if ($active !== null) {
            throw ValidationException::withMessages([
                'pregnancy_choice' => 'Une grossesse active existe déjà. Choisissez « Continuer cette grossesse » pour éviter un doublon.',
            ]);
        }

        $pregnancyData = Arr::get($data, 'pregnancy_data', []);
        $lastPeriod = $pregnancyData['last_menstrual_period'] ?? null;
        $submittedDueDate = $pregnancyData['estimated_due_date'] ?? null;
        $dueDate = $lastPeriod
            ? $this->dating->estimatedDueDate($lastPeriod)->toDateString()
            : $submittedDueDate;

        return Pregnancy::query()->create([
            'patient_id' => $patient->getKey(),
            'status' => PregnancyStatus::Ongoing,
            'last_menstrual_period' => $lastPeriod,
            'estimated_due_date' => $dueDate,
            'dating_method' => $lastPeriod
                ? PregnancyDatingMethod::LastMenstrualPeriod
                : ($dueDate ? PregnancyDatingMethod::ManualCorrection : null),
            'dating_confirmed_at' => ($lastPeriod || $dueDate) ? now() : null,
            'dating_confirmed_by' => ($lastPeriod || $dueDate) ? $actor->getKey() : null,
            'gravidity' => $pregnancyData['gravidity'] ?? null,
            'parity' => $pregnancyData['parity'] ?? null,
            'risk_factors' => $pregnancyData['risk_factors'] ?? null,
            'started_at' => $lastPeriod ?: ($orientation->episode->started_at?->toDateString() ?? today()->toDateString()),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }

    /** @param array<string, mixed> $pregnancyData */
    public function syncClinicalDetails(Pregnancy $pregnancy, array $pregnancyData, User $actor): Pregnancy
    {
        $values = collect(['gravidity', 'parity', 'risk_factors'])
            ->filter(fn (string $key): bool => array_key_exists($key, $pregnancyData))
            ->mapWithKeys(fn (string $key): array => [$key => $pregnancyData[$key]])
            ->all();

        if ($values !== []) {
            $pregnancy->fill($values + ['updated_by' => $actor->getKey()])->save();
        }

        return $pregnancy->fresh();
    }

    /** @return array<string, mixed> */
    public function consultationSnapshot(Pregnancy $pregnancy): array
    {
        return [
            'gravidity' => $pregnancy->gravidity,
            'parity' => $pregnancy->parity,
            'last_menstrual_period' => $pregnancy->last_menstrual_period?->toDateString(),
            'estimated_due_date' => $pregnancy->estimated_due_date?->toDateString(),
            'risk_factors' => $pregnancy->risk_factors,
            'dating_method' => $pregnancy->dating_method?->value,
        ];
    }
}
