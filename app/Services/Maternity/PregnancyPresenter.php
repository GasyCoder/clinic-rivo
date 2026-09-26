<?php

namespace App\Services\Maternity;

use App\Enums\PregnancyStatus;
use App\Models\Episode;
use App\Models\MaternityRecord;
use App\Models\Pregnancy;
use App\Models\User;
use Illuminate\Support\Collection;

final class PregnancyPresenter
{
    public function __construct(private readonly PregnancyDatingService $dating) {}

    /** @return array<string, mixed> */
    public function summary(Pregnancy $pregnancy, mixed $at = null): array
    {
        $pregnancy->loadMissing(['maternityRecords.episode.careRecord', 'maternityRecords.orientation']);
        $age = $this->dating->gestationalAge($pregnancy, $at ?? now());
        $last = $pregnancy->maternityRecords
            ->sortBy(fn (MaternityRecord $record) => $record->episode?->started_at?->getTimestamp() ?? $record->created_at?->getTimestamp() ?? 0)
            ->last();

        return [
            'uuid' => $pregnancy->uuid,
            'reference' => $pregnancy->reference(),
            'status' => $pregnancy->status->value,
            'status_label' => $pregnancy->status->label(),
            'last_menstrual_period' => $pregnancy->last_menstrual_period?->toDateString(),
            'estimated_due_date' => $pregnancy->estimated_due_date?->toDateString(),
            'dating_method' => $pregnancy->dating_method?->value,
            'dating_method_label' => $pregnancy->dating_method?->label(),
            'dating_confirmed_at' => $pregnancy->dating_confirmed_at,
            'gravidity' => $pregnancy->gravidity,
            'parity' => $pregnancy->parity,
            'risk_factors' => $pregnancy->risk_factors,
            'gestational_age_weeks' => $age['weeks'] ?? null,
            'gestational_age_days' => $age['days'] ?? null,
            'gestational_age_label' => $age['label'] ?? null,
            'consultations_count' => $pregnancy->maternityRecords->count(),
            'last_consultation_at' => $last?->episode?->started_at ?? $last?->created_at,
            'delivered_at' => $pregnancy->delivered_at,
            'ended_at' => $pregnancy->ended_at,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(Pregnancy $pregnancy, User $viewer, ?MaternityRecord $current = null): array
    {
        $pregnancy->loadMissing(['maternityRecords.episode.careRecord', 'maternityRecords.orientation']);

        return $pregnancy->maternityRecords
            ->sortBy(fn (MaternityRecord $record) => $record->episode?->started_at?->getTimestamp() ?? $record->created_at?->getTimestamp() ?? 0)
            ->values()
            ->map(fn (MaternityRecord $record, int $index): array => [
                'uuid' => $record->uuid,
                'number' => $index + 1,
                'label' => $this->consultationLabel($record),
                'consultation_at' => $record->episode?->started_at ?? $record->created_at,
                'episode_number' => $record->episode?->episode_number,
                'gestational_age_weeks' => $record->gestational_age_weeks ?? ($record->prenatal_data['gestational_age_weeks'] ?? null),
                'gestational_age_days' => $record->gestational_age_days ?? ($record->prenatal_data['gestational_age_days'] ?? 0),
                'gestational_age_label' => $this->dating->label(
                    $record->gestational_age_weeks ?? ($record->prenatal_data['gestational_age_weeks'] ?? null),
                    $record->gestational_age_days ?? ($record->prenatal_data['gestational_age_days'] ?? 0),
                ),
                'is_current' => $current?->is($record) ?? false,
                'read_only' => ! ($current?->is($record) ?? false),
                'url' => $viewer->can('maternity.view') && $record->orientation
                    ? route('maternity.orientations.show', $record->orientation)
                    : null,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    public function previous(Pregnancy $current, User $viewer): array
    {
        return $this->previousForPatient($current->patient_id, $viewer, $current->getKey());
    }

    /** @return list<array<string, mixed>> */
    public function previousForPatient(int $patientId, User $viewer, ?int $exceptId = null): array
    {
        return Pregnancy::query()
            ->where('patient_id', $patientId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereIn('status', [PregnancyStatus::Delivered->value, PregnancyStatus::Ended->value])
            ->with(['maternityRecords.episode.careRecord', 'maternityRecords.orientation'])
            ->latest('started_at')->latest('id')->get()
            ->map(fn (Pregnancy $pregnancy): array => $this->summary($pregnancy, $pregnancy->ended_at ?? $pregnancy->delivered_at ?? now()) + [
                'history' => $this->history($pregnancy, $viewer),
            ])->all();
    }

    /**
     * Résumé de grossesse par identifiant local de passage, calculé en lot
     * pour la page Maternité.
     *
     * @param Collection<int, int> $episodeIds
     * @return array<int, array<string, mixed>|null>
     */
    public function forEpisodes(Collection $episodeIds): array
    {
        $episodes = Episode::query()
            ->whereIn('id', $episodeIds)
            ->with([
                'maternityRecord.pregnancy.maternityRecords.episode',
                'patient.pregnancies' => fn ($query) => $query->ongoing()->with('maternityRecords.episode'),
            ])->get();

        return $episodes->mapWithKeys(function (Episode $episode): array {
            $pregnancy = $episode->maternityRecord?->pregnancy
                ?? $episode->patient?->pregnancies->sortByDesc('id')->first();

            return [$episode->getKey() => $pregnancy ? $this->summary($pregnancy, $episode->started_at ?? now()) : null];
        })->all();
    }

    private function consultationLabel(MaternityRecord $record): string
    {
        if (filled($record->delivery_data['occurred_at'] ?? null)) {
            return 'Accouchement';
        }

        if (collect($record->labor_data ?? [])->filter(fn ($value) => filled($value) && $value !== 'UNKNOWN')->isNotEmpty()) {
            return 'Évaluation du travail';
        }

        if (collect($record->prenatal_data ?? [])->filter(fn ($value) => filled($value))->isNotEmpty()) {
            return 'Consultation prénatale';
        }

        return 'Consultation Maternité';
    }
}
