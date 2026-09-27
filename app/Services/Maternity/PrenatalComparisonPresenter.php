<?php

namespace App\Services\Maternity;

use App\Models\Episode;
use App\Models\MaternityRecord;
use App\Models\Pregnancy;
use Carbon\CarbonImmutable;

/**
 * Comparaison factuelle entre la consultation précédente de la même grossesse
 * et celle-ci (ADR-201).
 *
 * Elle chiffre des écarts — « +2 kg », « +4 cm », « 9 semaines et 2 jours plus tard » —
 * et s'arrête là : aucune conclusion médicale n'est tirée ici, et Vue n'en
 * calcule aucune. Une valeur absente d'un côté ne produit aucun écart.
 */
final class PrenatalComparisonPresenter
{
    /** Les mesures chiffrées dont l'écart se lit sans interprétation. */
    private const NUMERIC = [
        'weight' => ['unit' => 'kg', 'decimals' => 1],
        'fundal_height' => ['unit' => 'cm', 'decimals' => 1],
        'fetal_heart_rate' => ['unit' => 'bpm', 'decimals' => 0],
    ];

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

        $previousAt = $previous->episode?->started_at ?? $previous->created_at;
        $currentAt = $currentEpisode->started_at ?? now();
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
            $before = $previousValues[$key] ?? null;
            $after = $currentValues[$key] ?? null;
            $delta = isset(self::NUMERIC[$key]) && is_numeric($before) && is_numeric($after)
                ? round((float) $after - (float) $before, self::NUMERIC[$key]['decimals'])
                : null;

            return $field + [
                'previous' => $before,
                'current' => $after,
                'delta' => $delta,
                'delta_label' => $delta === null ? null : $this->signed($delta, self::NUMERIC[$key]),
            ];
        })->filter(fn (array $field) => $field['previous'] !== null || $field['current'] !== null)->values()->all();

        return [
            'previous_visit' => ['at' => $previousAt, 'record_uuid' => $previous->uuid, 'label' => 'Consultation précédente'],
            'current_visit' => [
                'at' => $currentAt,
                'record_uuid' => $current?->uuid,
                // Relue plus tard, une consultation n'est plus « aujourd'hui ».
                'label' => CarbonImmutable::parse($currentAt)->isToday() ? 'Aujourd’hui' : 'Ce passage',
            ],
            'interval_label' => $this->interval($previousAt, $currentAt),
            'fields' => $fields,
        ];
    }

    /** @return array<string, mixed> */
    private function values(?MaternityRecord $record, ?Episode $episode, ?Pregnancy $pregnancy = null): array
    {
        $care = $episode?->careRecord;
        $age = $record && ($record->gestational_age_weeks ?? ($record->prenatal_data['gestational_age_weeks'] ?? null)) !== null
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
            'fundal_height' => isset($record?->prenatal_data['fundal_height_cm']) && $record->prenatal_data['fundal_height_cm'] !== ''
                ? (float) $record->prenatal_data['fundal_height_cm']
                : null,
            'fetal_heart_rate' => isset($record?->prenatal_data['fetal_heart_rate']) && $record->prenatal_data['fetal_heart_rate'] !== ''
                ? (int) $record->prenatal_data['fetal_heart_rate']
                : null,
        ];
    }

    /** « +2 kg », « −4 bpm », « 0 cm » — le signe dit le sens, jamais un jugement. */
    private function signed(float $delta, array $format): string
    {
        $number = number_format(abs($delta), $format['decimals'], ',', ' ');
        $number = $format['decimals'] > 0 ? rtrim(rtrim($number, '0'), ',') : $number;
        $sign = $delta > 0 ? '+' : ($delta < 0 ? '−' : '');

        return "{$sign}{$number} {$format['unit']}";
    }

    /** Le temps écoulé entre les deux passages, dit comme un terme. */
    private function interval(mixed $from, mixed $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }

        $days = (int) CarbonImmutable::parse($from)->startOfDay()->diffInDays(CarbonImmutable::parse($to)->startOfDay(), false);

        if ($days <= 0) {
            return null;
        }

        // « SA » désigne un terme, pas une durée : l'intervalle se dit en semaines.
        $weeks = intdiv($days, 7);
        $rest = $days % 7;
        $parts = array_filter([
            $weeks > 0 ? $weeks.' semaine'.($weeks > 1 ? 's' : '') : null,
            $rest > 0 ? $rest.' jour'.($rest > 1 ? 's' : '') : null,
        ]);

        return implode(' et ', $parts).' plus tard';
    }
}
