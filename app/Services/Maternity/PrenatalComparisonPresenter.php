<?php

namespace App\Services\Maternity;

use App\Models\Episode;
use App\Models\MaternityRecord;
use App\Models\Pregnancy;

/** Comparaison factuelle uniquement : aucune conclusion médicale. */
final class PrenatalComparisonPresenter
{
    public function __construct(private readonly PregnancyDatingService $dating) {}

    /** @return array<string, mixed>|null */
    public function present(Pregnancy $pregnancy, Episode $currentEpisode, ?MaternityRecord $current): ?array
    {
        $pregnancy->loadMissing('maternityRecords.episode.careRecord');
        $records = $pregnancy->maternityRecords
            ->sortBy(fn (MaternityRecord $record) => $record->episode?->started_at?->getTimestamp() ?? $record->created_at?->getTimestamp() ?? 0)
            ->values();
        $previous = $records
            ->filter(fn (MaternityRecord $record) => ! $current?->is($record))
            ->filter(fn (MaternityRecord $record) => ($record->episode?->started_at ?? $record->created_at) < ($currentEpisode->started_at ?? now()))
            ->last();

        if ($previous === null) {
            return null;
        }

        $previousValues = $this->values($previous, $previous->episode);
        $currentValues = $this->values($current, $currentEpisode, $pregnancy);
        $fields = collect([
            ['key' => 'gestational_age', 'label' => 'Terme', 'unit' => null],
            ['key' => 'weight', 'label' => 'Poids', 'unit' => 'kg'],
            ['key' => 'blood_pressure', 'label' => 'TA', 'unit' => 'mmHg'],
            ['key' => 'fundal_height', 'label' => 'Hauteur utérine', 'unit' => 'cm'],
            ['key' => 'fetal_heart_rate', 'label' => 'BCF', 'unit' => 'bpm'],
        ])->map(function (array $field) use ($previousValues, $currentValues): array {
            $key = $field['key'];
            $previous = $previousValues[$key] ?? null;
            $current = $currentValues[$key] ?? null;
            $delta = $key === 'weight' && is_numeric($previous) && is_numeric($current)
                ? round((float) $current - (float) $previous, 2)
                : null;

            return $field + ['previous' => $previous, 'current' => $current, 'delta' => $delta];
        })->filter(fn (array $field) => $field['previous'] !== null || $field['current'] !== null)->values()->all();

        return [
            'previous_visit' => ['at' => $previous->episode?->started_at ?? $previous->created_at, 'record_uuid' => $previous->uuid],
            'current_visit' => ['at' => $currentEpisode->started_at ?? now(), 'record_uuid' => $current?->uuid],
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private function values(?MaternityRecord $record, ?Episode $episode, ?Pregnancy $pregnancy = null): array
    {
        $care = $episode?->careRecord;
        $age = $record
            ? $this->dating->label(
                $record->gestational_age_weeks ?? ($record->prenatal_data['gestational_age_weeks'] ?? null),
                $record->gestational_age_days ?? ($record->prenatal_data['gestational_age_days'] ?? 0),
            )
            : ($pregnancy ? ($this->dating->gestationalAge($pregnancy, $episode?->started_at ?? now())['label'] ?? null) : null);

        return [
            'gestational_age' => $age,
            'weight' => $care?->weight_kg !== null ? (float) $care->weight_kg : null,
            'blood_pressure' => $care?->blood_pressure_systolic && $care?->blood_pressure_diastolic
                ? "{$care->blood_pressure_systolic}/{$care->blood_pressure_diastolic}"
                : null,
            'fundal_height' => isset($record?->prenatal_data['fundal_height_cm']) ? (float) $record->prenatal_data['fundal_height_cm'] : null,
            'fetal_heart_rate' => isset($record?->prenatal_data['fetal_heart_rate']) ? (int) $record->prenatal_data['fetal_heart_rate'] : null,
        ];
    }
}
