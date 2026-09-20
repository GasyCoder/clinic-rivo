<?php

namespace App\Http\Controllers;

use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\Patient;
use App\Models\PatientNewbornLink;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use App\Support\Documents\MaternitySheetSection;
use App\Support\Documents\MedicalRecordSheet;
use App\Support\EpisodePathwayTimeline;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only aggregation of everything already recorded for one passage —
 * orientations, Soins record, Médecine consultations, billing — scattered
 * today across per-module screens (Care, Medicine, the patient account tab).
 * No mutation lives here: every action still happens on the module screen
 * that owns it, gated by that module's own permission, exactly as before.
 */
class EpisodeController extends Controller
{
    public function show(Request $request, Episode $episode, ClinicalRichTextSanitizer $richText, EpisodePathwayTimeline $pathway, MaternitySheetSection $maternity): Response
    {
        $user = $request->user();
        $canViewCare = $user->can('care.view');
        $canViewVitals = $user->can('vitals.view');
        $canViewMedicalRecord = $user->can('medical_record.view');
        $canViewDiagnoses = $user->can('diagnoses.view');
        $canViewPrescriptions = $user->can('prescriptions.view');
        $canViewBilling = $user->can('billing.view');

        $episode->load([
            'patient:id,uuid,patient_number,first_name,last_name',
            ...($canViewCare ? [
                'careRecord',
                'careRecord.procedures' => fn ($query) => $query
                    ->with('performer:id,name')
                    ->orderBy('performed_at'),
            ] : []),
            ...($canViewMedicalRecord ? [
                'consultations' => fn ($query) => $query->orderBy('consulted_at'),
                'consultations.doctor:id,name',
                'medicalDischarge',
                ...($canViewDiagnoses ? [
                    'consultations.diagnoses' => fn ($query) => $query->orderBy('created_at'),
                    'consultations.diagnoses.recordedBy:id,name',
                    'consultations.diagnoses.cancellation.cancelledBy:id,name',
                ] : []),
                ...($canViewPrescriptions ? [
                    'consultations.prescriptions' => fn ($query) => $query->orderBy('prescribed_at'),
                    'consultations.prescriptions.lines',
                    'consultations.prescriptions.prescribedBy:id,name',
                ] : []),
            ] : []),
        ]);

        $billing = null;

        if ($canViewBilling) {
            // One line per BillableItem, each carrying its own invoice
            // number/state through the existing invoiceLine relation —
            // a single table instead of two disconnected lists that forced
            // the reader to mentally join "this prestation" to "that
            // invoice" themselves.
            $billableItems = BillableItem::query()
                ->where('episode_id', $episode->id)
                ->with('invoiceLine.invoice:id,uuid,invoice_number,status')
                ->latest()
                ->get();
            $invoices = $episode->invoices()->get();

            $billing = [
                'items' => $billableItems->map(fn (BillableItem $item) => [
                    'uuid' => $item->uuid,
                    'source_module' => $item->source_module,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'patient_amount' => $item->patient_amount ?? $item->total_amount,
                    'status' => $item->status->value,
                    'invoice' => $item->invoiceLine?->invoice ? [
                        'uuid' => $item->invoiceLine->invoice->uuid,
                        'invoice_number' => $item->invoiceLine->invoice->invoice_number,
                        'status' => $item->invoiceLine->invoice->status->value,
                    ] : null,
                ])->values(),
                'total_amount' => Money::fromMinor($invoices->sum(fn ($invoice) => Money::toMinor($invoice->total_amount))),
                'paid_amount' => Money::fromMinor($invoices->sum(fn ($invoice) => Money::toMinor($invoice->paid_amount))),
                'balance_amount' => Money::fromMinor($invoices->sum(fn ($invoice) => Money::toMinor($invoice->balance_amount))),
            ];
        }

        return Inertia::render('Episodes/Show', [
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
                'status' => $episode->status->value,
                'priority' => $episode->priority->value,
                'administrative_status' => $episode->administrative_status?->value,
                'medical_status' => $episode->medical_status?->value,
                'medical_status_label' => $episode->medical_status?->label(),
                'financial_mode' => $episode->financial_mode?->value,
                'started_at' => $episode->started_at,
                'ended_at' => $episode->ended_at,
                'emergency_contact_name' => $episode->emergency_contact_name,
                'emergency_contact_phone' => $episode->emergency_contact_phone,
                'emergency_contact_relationship' => $episode->emergency_contact_relationship,
                'emergency_contact_email' => $episode->emergency_contact_email,
                'patient' => [
                    'uuid' => $episode->patient->uuid,
                    'patient_number' => $episode->patient->patient_number,
                    'first_name' => $episode->patient->first_name,
                    'last_name' => $episode->patient->last_name,
                ],
                // ADR-117 : le parcours complet — Réception, services, Pharmacie,
                // Caisse, sortie — composé une seule fois, et le même que celui
                // que le dossier du patient résume en frise.
                'pathway' => $pathway->forEpisode($episode, $user),
                'care_record' => $canViewCare && $episode->careRecord ? [
                    ...($canViewVitals ? [
                        'blood_group' => $episode->careRecord->blood_group,
                        'blood_pressure_systolic' => $episode->careRecord->blood_pressure_systolic,
                        'blood_pressure_diastolic' => $episode->careRecord->blood_pressure_diastolic,
                        'heart_rate' => $episode->careRecord->heart_rate,
                        'spo2' => $episode->careRecord->spo2,
                        'temperature_celsius' => $episode->careRecord->temperature_celsius,
                        'known_diabetes' => $episode->careRecord->known_diabetes,
                        'height_cm' => $episode->careRecord->height_cm,
                        'weight_kg' => $episode->careRecord->weight_kg,
                        'bmi' => $episode->careRecord->bmi,
                        'smoker' => $episode->careRecord->smoker,
                        'alcohol' => $episode->careRecord->alcohol,
                    ] : []),
                    'allergy_snapshot' => $episode->careRecord->allergy_snapshot ?? [],
                    'no_procedure_reason' => $episode->careRecord->no_procedure_reason,
                    'diagnostic_note' => $episode->careRecord->diagnostic_note,
                    'transmission_reason' => $episode->careRecord->transmission_reason,
                    'diagnostic_note_html' => $episode->careRecord->diagnostic_note_html,
                    'transmission_reason_html' => $episode->careRecord->transmission_reason_html,
                    'procedures' => $episode->careRecord->procedures->map(fn ($procedure) => [
                        'uuid' => $procedure->uuid,
                        'name' => $procedure->procedure_name,
                        'code' => $procedure->procedure_code,
                        'quantity' => $procedure->quantity,
                        'notes' => $procedure->notes,
                        'performer' => $procedure->performer?->name,
                        'performed_at' => $procedure->performed_at,
                    ])->values(),
                ] : null,
                'consultations' => $canViewMedicalRecord ? $episode->consultations->map(fn ($consultation) => [
                    'id' => $consultation->id,
                    'reason' => $richText->toSafeHtml($consultation->reason),
                    'clinical_exam' => $richText->toSafeHtml($consultation->clinical_exam),
                    'decision' => $consultation->decision?->value,
                    'decision_label' => $consultation->decision?->label(),
                    'decision_notes' => $consultation->decision_notes,
                    'doctor' => $consultation->doctor?->name,
                    'consulted_at' => $consultation->consulted_at,
                    'diagnoses' => $canViewDiagnoses ? $consultation->diagnoses->map(fn ($diagnosis) => [
                        'id' => $diagnosis->id,
                        'type' => $diagnosis->type->value,
                        'description' => $diagnosis->description,
                        'source' => $diagnosis->is_manual ? 'MANUAL' : 'CATALOG',
                        'source_label' => $diagnosis->is_manual ? 'Manuel' : 'Catalogue',
                        'code' => $diagnosis->is_manual ? $diagnosis->manual_code : $diagnosis->catalog_code_snapshot,
                        'notes' => $diagnosis->notes,
                        'recorded_by' => $diagnosis->recordedBy?->name,
                        'created_at' => $diagnosis->created_at,
                        'cancelled' => $diagnosis->cancellation !== null,
                        'cancelled_by' => $diagnosis->cancellation?->cancelledBy?->name,
                        'cancelled_at' => $diagnosis->cancellation?->cancelled_at,
                    ])->values() : [],
                    'prescriptions' => $canViewPrescriptions ? $consultation->prescriptions->map(fn ($prescription) => [
                        'uuid' => $prescription->uuid,
                        'status' => $prescription->status->value,
                        'prescribed_by' => $prescription->prescribedBy?->name,
                        'prescribed_at' => $prescription->prescribed_at,
                        'cancel_reason' => $prescription->cancel_reason,
                        'lines' => $prescription->lines->map(fn ($line) => [
                            'id' => $line->id,
                            'medication_name' => $line->medication_name,
                            'quantity' => $line->quantity,
                            'dosage' => $line->dosage,
                            'frequency' => $line->frequency,
                            'duration' => $line->duration,
                            'instructions' => $line->instructions,
                            'is_manual_entry' => $line->is_manual_entry,
                        ])->values(),
                    ])->values() : [],
                ])->values() : [],
                'medical_discharge' => $canViewMedicalRecord && $episode->medicalDischarge ? [
                    'type' => $episode->medicalDischarge->type->value,
                    'type_label' => $episode->medicalDischarge->type->label(),
                    'final_diagnosis' => $episode->medicalDischarge->final_diagnosis,
                    'patient_condition' => $episode->medicalDischarge->patient_condition,
                    'recommendations' => $episode->medicalDischarge->recommendations,
                    'follow_up_at' => $episode->medicalDischarge->follow_up_at,
                    'observations' => $episode->medicalDischarge->observations,
                    'transfer_destination' => $episode->medicalDischarge->transfer_destination,
                    'death_occurred_at' => $episode->medicalDischarge->death_occurred_at,
                    'death_place' => $episode->medicalDischarge->death_place,
                    'death_causes' => $episode->medicalDischarge->death_causes,
                    'discharged_at' => $episode->medicalDischarge->discharged_at,
                ] : null,
            ],
            'billing' => $billing,
            // ADR-144 : les bébés du dossier Maternité de ce passage, et l'accès à leur dossier patient.
            'maternityBabies' => $maternity->forPassage($episode, $user),
            'capabilities' => [
                'can_view_care' => $canViewCare,
                'can_view_vitals' => $canViewVitals,
                'can_view_medical_record' => $canViewMedicalRecord,
                'can_view_diagnoses' => $canViewDiagnoses,
                'can_view_prescriptions' => $canViewPrescriptions,
                'can_view_billing' => $canViewBilling,
                'can_view_treatment_journal' => $user->can('treatment_journal.view'),
            ],
        ]);
    }

    /**
     * ADR-116 — le « DOSSIER MÉDICAL » de la clinique, imprimé depuis ce qui
     * est déjà consigné dans le passage. Gardé par la même permission que
     * la page « Détail du passage » elle-même : les sections plus sensibles
     * (constantes, antécédents) restent gouvernées à l'intérieur par leur
     * propre permission (`vitals.view`, `patients.medical_history.view`).
     */
    public function printMedicalRecord(Request $request, Episode $episode, MedicalRecordSheet $sheet): Response
    {
        return Inertia::render('Medicine/MedicalRecordPrint', $sheet->present($episode, $request->user()));
    }

    /** ADR-145 — le même dossier médical, pour un patient qui n'a peut-être encore aucun passage. */
    public function printPatientMedicalRecord(Request $request, Patient $patient, MedicalRecordSheet $sheet): Response
    {
        return Inertia::render('Medicine/MedicalRecordPrint', $sheet->presentForPatient($patient, $request->user()));
    }

    /**
     * ADR-146 — le dossier médical d'un bébé qui n'est pas encore patient, lu depuis sa fiche chez sa mère.
     *
     * S'il est devenu patient entre-temps, on va à son dossier patient : il n'y a qu'un dossier par bébé, et
     * un lien ancien ne doit pas montrer une version périmée.
     */
    public function printNewbornMedicalRecord(Request $request, Episode $episode, string $newbornUuid, MedicalRecordSheet $sheet): Response|RedirectResponse
    {
        $record = $episode->maternityRecord()->firstOrFail();

        $link = PatientNewbornLink::query()
            ->where('maternity_record_id', $record->getKey())
            ->where('newborn_uuid', $newbornUuid)
            ->with('patient:id,uuid')
            ->first();

        if ($link?->patient) {
            return redirect("/patients/{$link->patient->uuid}/dossier-medical");
        }

        return Inertia::render('Medicine/MedicalRecordPrint', $sheet->presentForNewborn($episode, $record, $newbornUuid, $request->user()));
    }
}
