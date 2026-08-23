<?php

namespace App\Services\Care;

use App\Models\CareRecord;
use App\Models\User;
use App\Support\BloodPressureAssessment;
use App\Support\BmiAssessment;
use App\Support\HeartRateAssessment;
use App\Support\OxygenSaturationAssessment;
use App\Support\TemperatureAssessment;

/**
 * Permission-aware, read-only projection of the nursing worksheet.
 *
 * Surgery and Anesthesia consume this projection instead of serializing the
 * Eloquent relation. That keeps Soins as the single source of truth while an
 * explicit DENY on vitals or medical history still wins server-side.
 */
class CareRecordReadModel
{
    public function __construct(
        private readonly BmiAssessment $bmiAssessment,
        private readonly BloodPressureAssessment $bloodPressureAssessment,
        private readonly HeartRateAssessment $heartRateAssessment,
        private readonly OxygenSaturationAssessment $oxygenSaturationAssessment,
        private readonly TemperatureAssessment $temperatureAssessment,
    ) {}

    /** @return array<string, mixed>|null */
    public function present(?CareRecord $record, User $viewer): ?array
    {
        if (! $record || ! $viewer->can('care.view')) {
            return null;
        }

        $record->loadMissing([
            'episode.patient',
            'creator:id,name',
            'updater:id,name',
            'procedures.performer:id,name',
        ]);

        $canViewVitals = $viewer->can('vitals.view');
        $canViewAllergies = $viewer->can('patients.medical_history.view');
        $patientAge = $this->patientAgeAtEpisode($record);

        return [
            'uuid' => $record->uuid,
            'read_only' => true,
            'can_view_vitals' => $canViewVitals,
            'can_view_allergies' => $canViewAllergies,
            ...($canViewVitals ? [
                'blood_group' => $record->blood_group,
                'blood_pressure_systolic' => $record->blood_pressure_systolic,
                'blood_pressure_diastolic' => $record->blood_pressure_diastolic,
                'blood_pressure_assessment' => $this->bloodPressureAssessment->classify(
                    $record->blood_pressure_systolic,
                    $record->blood_pressure_diastolic,
                ),
                'heart_rate' => $record->heart_rate,
                'heart_rate_assessment' => $this->heartRateAssessment->classify($record->heart_rate, $patientAge),
                'spo2' => $record->spo2,
                'spo2_assessment' => $this->oxygenSaturationAssessment->classify($record->spo2),
                'temperature_celsius' => $record->temperature_celsius,
                'temperature_assessment' => $this->temperatureAssessment->classify($record->temperature_celsius),
                'known_diabetes' => $record->known_diabetes,
                'height_cm' => $record->height_cm,
                'weight_kg' => $record->weight_kg,
                'bmi' => $record->bmi,
                'bmi_assessment' => $this->bmiAssessment->classify(
                    $record->bmi,
                    $patientAge,
                ),
                'smoker' => $record->smoker,
            ] : []),
            ...($canViewAllergies ? [
                'allergy_note' => $record->allergy_note,
                'allergy_snapshot' => $record->allergy_snapshot ?? [],
            ] : []),
            'no_procedure_reason' => $record->no_procedure_reason,
            'diagnostic_note' => $record->diagnostic_note,
            'transmission_reason' => $record->transmission_reason,
            'created_by' => $record->creator?->name,
            'updated_by' => $record->updater?->name,
            'updated_at' => $record->updated_at,
            'procedures' => $record->procedures->map(fn ($procedure) => [
                'uuid' => $procedure->uuid,
                'code' => $procedure->procedure_code,
                'name' => $procedure->procedure_name,
                'quantity' => $procedure->quantity,
                'notes' => $procedure->notes,
                'allergy_checked_at' => $procedure->allergy_checked_at,
                'performed_by' => $procedure->performer?->name,
                'performed_at' => $procedure->performed_at,
            ])->values(),
        ];
    }

    private function patientAgeAtEpisode(CareRecord $record): ?int
    {
        $patient = $record->episode?->patient;

        if (! $patient) {
            return null;
        }

        if ($patient->birth_date) {
            $referenceDate = $record->episode->started_at ?? now();

            if ($patient->birth_date->isAfter($referenceDate)) {
                return null;
            }

            return (int) $patient->birth_date->diffInYears($referenceDate);
        }

        return $patient->declared_age;
    }
}
