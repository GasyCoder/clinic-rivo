<?php

namespace App\Support;

use App\Models\HospitalStay;
use App\Models\HospitalStayMovement;
use App\Models\VitalSignReading;

/**
 * ADR-161 — ce que le séjour sait de l'emplacement et de la surveillance du
 * patient, pour son écran.
 *
 * Les repères des constantes sont ceux de la fiche Soins, lus selon l'âge au
 * passage (ADR-125) : aucun seuil n'est recalculé ici, les évaluations
 * existantes classent chaque relevé.
 */
class HospitalStaySurveillance
{
    public function __construct(
        private readonly BloodPressureAssessment $bloodPressure,
        private readonly HeartRateAssessment $heartRate,
        private readonly OxygenSaturationAssessment $oxygenSaturation,
        private readonly TemperatureAssessment $temperature,
    ) {}

    /** @return list<array<string, mixed>> */
    public function movements(HospitalStay $stay): array
    {
        return $stay->movements()
            ->with(['movedBy:id,name'])
            ->get()
            ->map(fn (HospitalStayMovement $movement): array => [
                'uuid' => $movement->uuid,
                'service' => $movement->service,
                'room_bed' => $movement->room_bed,
                'care_level' => $movement->care_level->value,
                'care_level_label' => $movement->care_level->label(),
                'started_at' => $movement->started_at,
                'ended_at' => $movement->ended_at,
                'reason' => $movement->reason,
                'moved_by' => $movement->movedBy?->name,
                'is_current' => $movement->ended_at === null,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function readings(HospitalStay $stay): array
    {
        $age = $this->patientAge($stay);

        return $stay->vitalReadings()
            ->with(['measuredBy:id,name', 'updatedBy:id,name'])
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (VitalSignReading $reading): array => $this->present($reading, $age))
            ->values()
            ->all();
    }

    /**
     * ADR-165 — le dernier relevé du séjour, pour la feuille de tour de salle.
     *
     * Les mêmes repères que l'onglet Surveillance : un seul classement des
     * constantes, jamais une seconde lecture des seuils. `null` quand personne
     * n'a encore relevé — jamais des tirets qui se liraient « normal ».
     *
     * @return array<string, mixed>|null
     */
    public function latestReading(HospitalStay $stay): ?array
    {
        $reading = $stay->vitalReadings()
            ->with(['measuredBy:id,name', 'updatedBy:id,name'])
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();

        return $reading ? $this->present($reading, $this->patientAge($stay)) : null;
    }

    /** @return array<string, mixed> */
    private function present(VitalSignReading $reading, ?int $age): array
    {
        return [
            'uuid' => $reading->uuid,
            'measured_at' => $reading->measured_at,
            'blood_pressure_systolic' => $reading->blood_pressure_systolic,
            'blood_pressure_diastolic' => $reading->blood_pressure_diastolic,
            'heart_rate' => $reading->heart_rate,
            'spo2' => $reading->spo2,
            'temperature_celsius' => $reading->temperature_celsius,
            'notes' => $reading->notes,
            'measured_by' => $reading->measuredBy?->name,
            'updated_by' => $reading->updatedBy?->name,
            'alerts' => array_values(array_filter([
                $this->bloodPressure->classify($reading->blood_pressure_systolic, $reading->blood_pressure_diastolic, $age),
                $this->heartRate->classify($reading->heart_rate, $age),
                $this->oxygenSaturation->classify($reading->spo2),
                $this->temperature->classify($reading->temperature_celsius, $age),
            ])),
        ];
    }

    private function patientAge(HospitalStay $stay): ?int
    {
        $episode = $stay->episode()->with('patient')->first();
        $patient = $episode?->patient;

        if (! $patient) {
            return null;
        }

        if ($patient->birth_date) {
            $reference = $episode->started_at ?? now();

            return $patient->birth_date->isAfter($reference)
                ? null
                : (int) $patient->birth_date->diffInYears($reference);
        }

        return $patient->declared_age;
    }
}
