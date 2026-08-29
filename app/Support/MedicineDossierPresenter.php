<?php

namespace App\Support;

use App\Enums\ConsultationDecision;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
use App\Enums\PrescriptionStatus;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Care\CareRecordReadModel;
use App\Services\Pharmacy\MedicineStockService;

class MedicineDossierPresenter
{
    public function __construct(
        private readonly EpisodeQueuePresenter $queuePresenter,
        private readonly MedicineStockService $medicineStock,
        private readonly CareRecordReadModel $careRecord,
    ) {}

    /** @return array<string, mixed> */
    public function present(
        EpisodeOrientation $orientation,
        User $user,
        bool $includeMedicineCatalog = false,
    ): array {
        $episode = $orientation->episode;
        $patient = $episode->patient;
        $consultation = $orientation->consultation;
        $careRecord = $episode->careRecord;
        $canViewMedicalRecord = $user->can('medical_record.view');
        $canViewDiagnoses = $user->can('diagnoses.view');
        $canViewPrescriptions = $user->can('prescriptions.view');
        $canViewPharmacyAvailability = $user->can('medicines.view')
            && $user->can('stock.availability.view');
        $isActive = $orientation->status === EpisodeOrientationStatus::InProgress
            && $episode->medicalDischarge === null;

        $base = $this->queuePresenter->present($orientation);
        $base['episode']['medical_status'] = $episode->medical_status?->value;
        $base['episode']['medical_status_label'] = $episode->medical_status?->label();
        $base['episode']['emergency_contact'] = [
            'name' => $episode->emergency_contact_name,
            'phone' => $episode->emergency_contact_phone,
            'relationship' => $episode->emergency_contact_relationship,
            'email' => $episode->emergency_contact_email,
        ];
        $base['episode']['patient'] = [
            ...$base['episode']['patient'],
            'phone' => $patient->phone,
            'email' => $patient->email,
            'address' => $patient->address,
            'sex_label' => $patient->sex->value === 'F' ? 'Féminin' : 'Masculin',
        ];

        return [
            'orientation' => $base,
            // Same permission-aware projection Care and Surgery/Anesthesia
            // read from (ADR-048): a single place computes the constants'
            // threshold assessments, so Médecine never re-derives them.
            'care_record' => $this->careRecord->present($careRecord, $user),
            'allergies' => $user->can('patients.medical_history.view')
                ? $patient->allergies->map(fn ($allergy) => [
                    'uuid' => $allergy->uuid,
                    'substance' => $allergy->substance,
                    'reaction' => $allergy->reaction,
                    'severity' => $allergy->severity?->value,
                ])->values()
                : [],
            'antecedents' => $user->can('patients.medical_history.view')
                ? $patient->antecedents->map(fn ($antecedent) => [
                    'uuid' => $antecedent->uuid,
                    'description' => $antecedent->description,
                ])->values()
                : [],
            'consultation' => $consultation ? [
                'id' => $consultation->getKey(),
                'doctor' => $consultation->doctor?->name,
                'reason' => $consultation->reason,
                'clinical_exam' => $consultation->clinical_exam,
                'decision' => $consultation->decision?->value,
                'decision_label' => $consultation->decision?->label(),
                'decision_notes' => $consultation->decision_notes,
                'consulted_at' => $consultation->consulted_at,
                'diagnoses' => $canViewDiagnoses
                    ? $consultation->diagnoses->map(fn ($diagnosis) => [
                        'id' => $diagnosis->getKey(),
                        'type' => $diagnosis->type->value,
                        'type_label' => $diagnosis->type === DiagnosisType::Final
                            ? 'Diagnostic final'
                            : 'Hypothèse',
                        'description' => $diagnosis->description,
                        'recorded_by' => $diagnosis->recordedBy?->name,
                        'recorded_at' => $diagnosis->created_at,
                        'cancelled' => $diagnosis->cancellation !== null,
                        'cancel_reason' => $diagnosis->cancellation?->reason,
                        'cancelled_by' => $diagnosis->cancellation?->cancelledBy?->name,
                        'cancelled_at' => $diagnosis->cancellation?->cancelled_at,
                        'correction' => $diagnosis->cancellation?->replacement_diagnosis_id !== null,
                        'can_cancel' => $isActive
                            && $user->can('diagnoses.update')
                            && $diagnosis->recorded_by === $user->getKey()
                            && $diagnosis->cancellation === null,
                        'can_edit' => $isActive
                            && $user->can('diagnoses.update')
                            && $diagnosis->recorded_by === $user->getKey()
                            && $diagnosis->cancellation === null,
                    ])->values()
                    : [],
                'prescriptions' => $canViewPrescriptions
                    ? $consultation->prescriptions
                        ->where('status', PrescriptionStatus::Active)
                        ->map(fn ($prescription) => [
                            'uuid' => $prescription->uuid,
                            'prescribed_at' => $prescription->prescribed_at ?? $prescription->created_at,
                            'can_edit' => $isActive && $user->can('prescriptions.update'),
                            'can_remove' => $isActive && $user->can('prescriptions.cancel'),
                            'lines' => $prescription->lines->map(fn ($line) => [
                                'id' => $line->getKey(),
                                'medicine_uuid' => $line->medicine?->catalogItem?->uuid,
                                'medication_name' => $line->medication_name,
                                'quantity' => $line->quantity,
                                'unit' => $line->medicine?->catalogItem?->unit,
                                'stock_available_at_prescription' => $line->stock_available_at_prescription,
                                'earliest_expiration_at' => $line->earliest_expiration_at?->toDateString(),
                                'dosage' => $line->dosage,
                                'frequency' => $line->frequency,
                                'duration' => $line->duration,
                                'instructions' => $line->instructions,
                                'is_manual_entry' => $line->is_manual_entry,
                                'catalog_review_status' => $line->catalog_review_status?->value,
                                'catalog_reviewed_at' => $line->catalog_reviewed_at,
                            ])->values(),
                        ])->values()
                    : [],
            ] : null,
            'medical_discharge' => $canViewMedicalRecord && $episode->medicalDischarge ? [
                'uuid' => $episode->medicalDischarge->uuid,
                'type' => $episode->medicalDischarge->type->value,
                'type_label' => $episode->medicalDischarge->type->label(),
                'final_diagnosis' => $episode->medicalDischarge->final_diagnosis,
                'patient_condition' => $episode->medicalDischarge->patient_condition,
                'discharge_prescription' => $episode->medicalDischarge->discharge_prescription,
                'recommendations' => $episode->medicalDischarge->recommendations,
                'follow_up_at' => $episode->medicalDischarge->follow_up_at,
                'observations' => $episode->medicalDischarge->observations,
                'transfer_destination' => $episode->medicalDischarge->transfer_destination,
                'death_occurred_at' => $episode->medicalDischarge->death_occurred_at,
                'death_place' => $episode->medicalDischarge->death_place,
                'death_causes' => $episode->medicalDischarge->death_causes,
                'discharged_at' => $episode->medicalDischarge->discharged_at,
                'created_by' => $episode->medicalDischarge->creator?->name,
            ] : null,
            'options' => [
                'decisions' => collect(ConsultationDecision::cases())->map(fn ($decision) => [
                    'value' => $decision->value,
                    'label' => $decision->label(),
                ])->values(),
                'diagnosis_types' => [
                    ['value' => DiagnosisType::Hypothesis->value, 'label' => 'Hypothèse diagnostique'],
                    ['value' => DiagnosisType::Final->value, 'label' => 'Diagnostic final'],
                ],
                'discharge_types' => collect(MedicalDischargeType::cases())->map(fn ($type) => [
                    'value' => $type->value,
                    'label' => $type->label(),
                ])->values(),
                'medicines' => $canViewPharmacyAvailability && $includeMedicineCatalog
                    ? $this->medicineStock->availableCatalog()
                    : [],
            ],
            'capabilities' => [
                'can_view_medical_record' => $canViewMedicalRecord,
                'can_update_consultation' => $isActive && $user->can('consultations.update'),
                'can_create_diagnosis' => $isActive && $user->can('diagnoses.create'),
                'can_create_prescription' => $isActive
                    && $user->can('prescriptions.create')
                    && $canViewPharmacyAvailability,
                'can_view_pharmacy_availability' => $canViewPharmacyAvailability,
                'can_cancel_prescription' => $isActive && $user->can('prescriptions.cancel'),
                'can_update_prescription' => $isActive && $user->can('prescriptions.update'),
                'can_discharge' => $isActive && $user->can('medical_discharge.create'),
                'can_manage_medical_history' => $user->can('patients.medical_history.manage'),
            ],
        ];
    }
}
