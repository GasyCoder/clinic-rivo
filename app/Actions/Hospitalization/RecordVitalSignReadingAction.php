<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeStatus;
use App\Models\HospitalStay;
use App\Models\User;
use App\Models\VitalSignReading;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-161 — un relevé de surveillance pendant le séjour.
 *
 * Mêmes bornes que la fiche Soins (`VitalSignRules`) : une tension refusée à
 * l'admission ne peut pas être acceptée ici. Un relevé ne s'ajoute qu'à un
 * séjour en cours ; il se corrige tant que le passage est ouvert.
 */
class RecordVitalSignReadingAction
{
    public const FIELDS = [
        'blood_pressure_systolic', 'blood_pressure_diastolic',
        'heart_rate', 'spo2', 'temperature_celsius',
    ];

    /** @param array<string, mixed> $data */
    public function record(HospitalStay $stay, array $data, User $actor): VitalSignReading
    {
        return DB::transaction(function () use ($stay, $data, $actor): VitalSignReading {
            $locked = HospitalStay::query()->lockForUpdate()->findOrFail($stay->getKey());

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['measured_at' => 'Le séjour est terminé : la surveillance est close.']);
            }

            return $locked->vitalReadings()->create([
                ...$this->values($data),
                'episode_id' => $locked->episode_id,
                'measured_at' => $data['measured_at'] ?? now(),
                'measured_by' => $actor->getKey(),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function correct(VitalSignReading $reading, array $data, User $actor): VitalSignReading
    {
        return DB::transaction(function () use ($reading, $data, $actor): VitalSignReading {
            $locked = VitalSignReading::query()->with('episode')->lockForUpdate()->findOrFail($reading->getKey());

            if ($locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['measured_at' => 'Le passage est clos : ce relevé ne se corrige plus.']);
            }

            $locked->update([
                ...$this->values($data),
                'measured_at' => $data['measured_at'] ?? $locked->measured_at,
                'updated_by' => $actor->getKey(),
            ]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = ($data[$field] ?? null) === '' ? null : ($data[$field] ?? null);
        }

        if (collect($values)->filter(fn ($value) => $value !== null)->isEmpty()) {
            throw ValidationException::withMessages([
                'blood_pressure_systolic' => 'Renseignez au moins une constante.',
            ]);
        }

        $values['notes'] = trim((string) ($data['notes'] ?? '')) ?: null;

        return $values;
    }
}
