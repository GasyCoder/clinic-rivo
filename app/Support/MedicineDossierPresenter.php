<?php

namespace App\Support;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ConsultationDecision;
use App\Enums\DiagnosisType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Enums\MedicalDischargeType;
use App\Enums\PrescriptionStatus;
use App\Models\CareOrder;
use App\Models\CatalogItem;
use App\Models\EpisodeOrientation;
use App\Models\ImagingRequest;
use App\Models\LabRequest;
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
        $canViewCareOrders = $user->can('care_orders.view');
        $canViewLabRequests = $user->can('laboratory_orders.view');
        $canViewImagingRequests = $user->can('imaging_orders.view');
        $isActive = $orientation->status === EpisodeOrientationStatus::InProgress
            && $episode->medicalDischarge === null;
        $referralDestinations = [
            CatalogModule::Maternity->value => CatalogModule::Maternity->label(),
            CatalogModule::Hospitalization->value => CatalogModule::Hospitalization->label(),
            CatalogModule::Transfer->value => CatalogModule::Transfer->label(),
            CatalogModule::Pediatrics->value => CatalogModule::Pediatrics->label(),
        ];

        // Whether "Continuer la prise en charge" is a genuine option rather
        // than an escape hatch from a real decision (item 12): only true
        // when something clinically justifies waiting.
        $pendingLabCount = $consultation
            ? LabRequest::query()->where('consultation_id', $consultation->getKey())->with('items')
                ->get()->filter(fn (LabRequest $r) => $r->displayStatus() !== 'COMPLETED')->count()
            : 0;
        $pendingImagingCount = $consultation
            ? ImagingRequest::query()->where('consultation_id', $consultation->getKey())->with('items')
                ->get()->filter(fn (ImagingRequest $r) => $r->displayStatus() !== 'COMPLETED')->count()
            : 0;
        $pendingCareOrderCount = $consultation
            ? CareOrder::query()->where('consultation_id', $consultation->getKey())->with('items.careRecordProcedures')
                ->get()->filter(fn (CareOrder $o) => $o->displayStatus() !== 'COMPLETED')->count()
            : 0;
        $pendingReasons = array_values(array_filter([
            $pendingLabCount > 0
                ? ($pendingLabCount > 1 ? "{$pendingLabCount} analyses en attente de résultat" : '1 analyse en attente de résultat')
                : null,
            $pendingImagingCount > 0
                ? ($pendingImagingCount > 1 ? "{$pendingImagingCount} examens d’imagerie en attente de compte rendu" : '1 examen d’imagerie en attente de compte rendu')
                : null,
            $pendingCareOrderCount > 0
                ? ($pendingCareOrderCount > 1 ? "{$pendingCareOrderCount} ordres de soins en cours" : '1 ordre de soins en cours')
                : null,
        ]));

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
                'care_orders' => $canViewCareOrders
                    ? CareOrder::query()
                        ->where('consultation_id', $consultation->getKey())
                        ->with(['items.careRecordProcedures', 'requestedBy:id,name'])
                        ->latest('ordered_at')
                        ->get()
                        ->map(fn (CareOrder $careOrder) => [
                            'uuid' => $careOrder->uuid,
                            'requested_by' => $careOrder->requestedBy?->name,
                            'instructions' => $careOrder->instructions,
                            'requires_return_to_medicine' => $careOrder->requires_return_to_medicine,
                            'status' => $careOrder->displayStatus(),
                            'ordered_at' => $careOrder->ordered_at,
                            'completed_at' => $careOrder->completed_at,
                            'items' => $careOrder->items->map(fn ($item) => [
                                'uuid' => $item->uuid,
                                'name' => $item->catalog_item_name_snapshot,
                                'code' => $item->catalog_item_code_snapshot,
                                'quantity' => $item->quantity,
                                'realized_quantity' => $item->realizedQuantity(),
                                'remaining_quantity' => $item->remainingQuantity(),
                                'not_performed_at' => $item->not_performed_at,
                                'not_performed_reason' => $item->not_performed_reason,
                                'instructions' => $item->instructions,
                            ])->values(),
                        ])->values()
                    : [],
                'lab_requests' => $canViewLabRequests
                    ? LabRequest::query()
                        ->where('consultation_id', $consultation->getKey())
                        ->with(['items.resultedBy:id,name', 'requestedBy:id,name'])
                        ->latest('requested_at')
                        ->get()
                        ->map(fn (LabRequest $labRequest) => [
                            'uuid' => $labRequest->uuid,
                            'requested_by' => $labRequest->requestedBy?->name,
                            'notes' => $labRequest->notes,
                            'status' => $labRequest->displayStatus(),
                            'requested_at' => $labRequest->requested_at,
                            'items' => $labRequest->items->map(fn ($item) => [
                                'uuid' => $item->uuid,
                                'name' => $item->catalog_item_name_snapshot,
                                'code' => $item->catalog_item_code_snapshot,
                                'result_value' => $item->result_value,
                                'result_notes' => $item->result_notes,
                                'resulted_at' => $item->resulted_at,
                                'resulted_by' => $item->resultedBy?->name,
                            ])->values(),
                        ])->values()
                    : [],
                'imaging_requests' => $canViewImagingRequests
                    ? ImagingRequest::query()
                        ->where('consultation_id', $consultation->getKey())
                        ->with(['items.resultedBy:id,name', 'requestedBy:id,name'])
                        ->latest('requested_at')
                        ->get()
                        ->map(fn (ImagingRequest $imagingRequest) => [
                            'uuid' => $imagingRequest->uuid,
                            'requested_by' => $imagingRequest->requestedBy?->name,
                            'notes' => $imagingRequest->notes,
                            'status' => $imagingRequest->displayStatus(),
                            'requested_at' => $imagingRequest->requested_at,
                            'items' => $imagingRequest->items->map(fn ($item) => [
                                'uuid' => $item->uuid,
                                'name' => $item->catalog_item_name_snapshot,
                                'code' => $item->catalog_item_code_snapshot,
                                'result_value' => $item->result_value,
                                'result_notes' => $item->result_notes,
                                'resulted_at' => $item->resulted_at,
                                'resulted_by' => $item->resultedBy?->name,
                            ])->values(),
                        ])->values()
                    : [],
                'surgical_requests' => $user->can('surgery.request')
                    ? $episode->surgicalRequests()
                        ->latest('created_at')
                        ->get()
                        ->map(fn ($request) => [
                            'uuid' => $request->uuid,
                            'procedure_name' => $request->procedure_name,
                            'procedure_details' => $request->procedure_details,
                            'notes' => $request->notes,
                            'status' => $request->status->value,
                        ])->values()
                    : [],
                'referrals' => $canViewMedicalRecord
                    ? $episode->orientations()
                        ->whereIn('destination_module', array_keys($referralDestinations))
                        ->latest('oriented_at')
                        ->get()
                        ->map(fn ($referral) => [
                            'uuid' => $referral->uuid,
                            'destination' => $referral->destination_module->value,
                            'destination_label' => $referral->destination_module->label(),
                            'reason' => $referral->reason,
                            'status' => $referral->status->value,
                            'status_label' => $referral->status->label(),
                            'oriented_at' => $referral->oriented_at,
                        ])->values()
                    : [],
            ] : null,
            'previous_consultations' => $canViewMedicalRecord
                ? $episode->consultations()
                    ->where('id', '!=', $consultation?->getKey())
                    ->with('doctor:id,name')
                    ->latest('consulted_at')
                    ->get()
                    ->map(fn ($previous) => [
                        'id' => $previous->getKey(),
                        'doctor' => $previous->doctor?->name,
                        'reason' => $previous->reason,
                        'decision_label' => $previous->decision?->label(),
                        'consulted_at' => $previous->consulted_at,
                    ])->values()
                : [],
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
                'care_order_catalog' => $isActive && $user->can('care_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Care->value)
                        ->where('clinician_orderable', true)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                    : [],
                'lab_catalog' => $isActive && $user->can('laboratory_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Laboratory->value)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                    : [],
                'imaging_catalog' => $isActive && $user->can('imaging_orders.create')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Imaging->value)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                    : [],
                'surgery_catalog' => $isActive && $user->can('surgery.request')
                    ? CatalogItem::query()
                        ->where('type', CatalogItemType::Service->value)
                        ->where('module', CatalogModule::Surgery->value)
                        ->orderBy('name')
                        ->get(['uuid', 'code', 'name'])
                    : [],
                'referral_destinations' => collect($referralDestinations)->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                ])->values(),
            ],
            'pending_reasons' => $pendingReasons,
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
                'can_mark_emergency' => $isActive
                    && $episode->priority !== EpisodePriority::Emergency
                    && $user->can('episodes.mark_emergency'),
                'can_create_care_order' => $isActive && $user->can('care_orders.create'),
                'can_view_care_orders' => $canViewCareOrders,
                'can_create_lab_request' => $isActive && $user->can('laboratory_orders.create'),
                'can_view_lab_requests' => $canViewLabRequests,
                'can_create_imaging_request' => $isActive && $user->can('imaging_orders.create'),
                'can_view_imaging_requests' => $canViewImagingRequests,
                'can_record_imaging_result' => $user->can('imaging_results.create'),
                'can_request_surgery' => $isActive && $user->can('surgery.request'),
                'can_request_maternity' => $isActive && $user->can('maternity.request'),
                'can_request_hospitalization' => $isActive && $user->can('hospitalization.request'),
                'can_request_transfer' => $isActive && $user->can('transfer.request'),
                'can_request_pediatrics' => $isActive && $user->can('pediatrics.request'),
                'can_defer_decision' => $isActive && $pendingReasons !== [],
            ],
        ];
    }
}
