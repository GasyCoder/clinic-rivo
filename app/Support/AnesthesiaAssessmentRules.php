<?php

namespace App\Support;

use Illuminate\Validation\Rule;

final class AnesthesiaAssessmentRules
{
    /** @return array<string, mixed> */
    public static function consultation(): array
    {
        return [
            'consultation_data' => ['sometimes', 'nullable', 'array'],
            'consultation_data.admission_reason' => ['nullable', 'string', 'max:2000'],
            'consultation_data.tobacco' => ['nullable', 'string', 'max:500'],
            'consultation_data.alcohol' => ['nullable', 'string', 'max:500'],
            'consultation_data.other_toxic_exposure' => ['nullable', 'string', 'max:500'],
            'consultation_data.medical_conditions' => ['nullable', 'array'],
            'consultation_data.medical_conditions.*' => ['string', Rule::in(self::medicalConditions())],
            'consultation_data.medical_history_notes' => ['nullable', 'string', 'max:4000'],
            'consultation_data.cough_duration' => ['nullable', 'string', 'max:255'],
            'consultation_data.sputum' => ['nullable', 'string', 'max:500'],
            'consultation_data.pain_notes' => ['nullable', 'string', 'max:1000'],
            'consultation_data.anesthetic_history' => ['nullable', 'string', 'max:4000'],
            'consultation_data.surgical_history' => ['nullable', 'string', 'max:4000'],
            'consultation_data.anesthetic_incidents' => ['nullable', 'string', 'max:2000'],
            'consultation_data.gyneco_obstetric' => ['nullable', 'array'],
            'consultation_data.gyneco_obstetric.gravida' => ['nullable', 'integer', 'min:0', 'max:30'],
            'consultation_data.gyneco_obstetric.para' => ['nullable', 'integer', 'min:0', 'max:30'],
            'consultation_data.gyneco_obstetric.abortion' => ['nullable', 'integer', 'min:0', 'max:30'],
            'consultation_data.gyneco_obstetric.last_menstrual_period' => ['nullable', 'date'],
            'consultation_data.gyneco_obstetric.contraception' => ['nullable', Rule::in(['ORAL', 'INJECTION'])],
            'consultation_data.gyneco_obstetric.contraception_date' => ['nullable', 'date'],
            'consultation_data.gyneco_obstetric.delivery_route' => ['nullable', Rule::in(['VAGINAL', 'CESAREAN'])],
            'consultation_data.gyneco_obstetric.delivery_date' => ['nullable', 'date'],
            'consultation_data.gyneco_obstetric.parity_status' => ['nullable', Rule::in(['PRIMIPAROUS', 'MULTIPAROUS'])],
            'consultation_data.gyneco_obstetric.obstetric_hemorrhage' => ['nullable', 'boolean'],
            'consultation_data.gyneco_obstetric.notes' => ['nullable', 'string', 'max:2000'],
            'consultation_data.clinical_exam' => ['nullable', 'array'],
            'consultation_data.clinical_exam.cardiovascular' => ['nullable', 'string', 'max:1000'],
            'consultation_data.clinical_exam.pulmonary' => ['nullable', 'string', 'max:1000'],
            'consultation_data.clinical_exam.neurological' => ['nullable', 'string', 'max:1000'],
            'consultation_data.clinical_exam.coloration' => ['nullable', 'string', 'max:500'],
            'consultation_data.clinical_exam.venous_access' => ['nullable', 'string', 'max:500'],
            'consultation_data.clinical_exam.spinal_access' => ['nullable', 'string', 'max:500'],
            'consultation_data.blood_pressure_systolic' => ['nullable', 'integer', 'min:40', 'max:300'],
            'consultation_data.blood_pressure_diastolic' => ['nullable', 'integer', 'min:20', 'max:200'],
            'consultation_data.heart_rate' => ['nullable', 'integer', 'min:20', 'max:300'],
            'consultation_data.oxygen_saturation' => ['nullable', 'integer', 'min:0', 'max:100'],
            'consultation_data.respiratory_rate' => ['nullable', 'integer', 'min:1', 'max:150'],
            'consultation_data.temperature_celsius' => ['nullable', 'numeric', 'min:25', 'max:45'],
            'consultation_data.weight_kg' => ['nullable', 'numeric', 'min:0.1', 'max:700'],
            'consultation_data.height_cm' => ['nullable', 'numeric', 'min:20', 'max:300'],
            'consultation_data.mouth_opening' => ['nullable', 'string', 'max:255'],
            'consultation_data.mallampati' => ['nullable', 'string', 'max:100'],
            'consultation_data.thyromental_distance' => ['nullable', 'string', 'max:255'],
            'consultation_data.cervical_spine' => ['nullable', 'string', 'max:500'],
            'consultation_data.dental_prosthesis' => ['nullable', 'string', 'max:500'],
            'consultation_data.other_prosthesis' => ['nullable', 'string', 'max:500'],
            'consultation_data.last_meal_time' => ['nullable', 'date_format:H:i'],
            'consultation_data.last_drink_time' => ['nullable', 'date_format:H:i'],
            'consultation_data.neuropsychological_status' => ['nullable', Rule::in(['CALM', 'RELAXED', 'ANXIOUS', 'AGITATED'])],
        ];
    }

    /** @return array<string, mixed> */
    public static function paraclinical(): array
    {
        $rules = [
            'paraclinical_data' => ['sometimes', 'nullable', 'array'],
            'paraclinical_data.blood_group' => ['nullable', Rule::in(['A', 'B', 'AB', 'O'])],
            'paraclinical_data.rhesus' => ['nullable', Rule::in(['POSITIVE', 'NEGATIVE'])],
            'paraclinical_data.transfusion_recommended_units' => ['nullable', 'integer', 'min:0', 'max:100'],
            'paraclinical_data.transfusion_received' => ['nullable', 'boolean'],
            'paraclinical_data.preoperative_transfusion_units' => ['nullable', 'integer', 'min:0', 'max:100'],
            'paraclinical_data.ultrasound_notes' => ['nullable', 'string', 'max:4000'],
            'paraclinical_data.ultrasonographer' => ['nullable', 'string', 'max:255'],
            'paraclinical_data.glasgow_eye' => ['nullable', 'integer', 'min:1', 'max:4'],
            'paraclinical_data.glasgow_verbal' => ['nullable', 'integer', 'min:1', 'max:5'],
            'paraclinical_data.glasgow_motor' => ['nullable', 'integer', 'min:1', 'max:6'],
            'paraclinical_data.apfel_score' => ['nullable', 'integer', 'min:0', 'max:4'],
            'paraclinical_data.associated_pathologies' => ['nullable', 'array'],
            'paraclinical_data.conclusion' => ['nullable', 'string', 'max:4000'],
            'paraclinical_data.therapeutic_recommendation' => ['nullable', 'string', 'max:4000'],
            'paraclinical_data.surgery_authorized' => ['nullable', 'boolean'],
            'paraclinical_data.asa_class' => ['nullable', 'string', 'max:50'],
            'paraclinical_data.nyha_class' => ['nullable', 'string', 'max:50'],
            'paraclinical_data.anesthesia_plan' => ['nullable', 'string', 'max:2000'],
            'paraclinical_data.fasting_hours' => ['nullable', 'numeric', 'min:0', 'max:72'],
        ];

        foreach (['hemoglobin', 'hematocrit', 'psa', 'creatinine', 'glycemia', 'urea', 'tdr', 'crp', 'widal_to', 'widal_th'] as $field) {
            $rules["paraclinical_data.laboratory.{$field}"] = ['nullable', 'string', 'max:255'];
        }

        foreach (['cardiac', 'respiratory', 'renal', 'digestive', 'neurological', 'gynecological', 'ent'] as $field) {
            $rules["paraclinical_data.associated_pathologies.{$field}"] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    public static function peroperative(): array
    {
        return [
            'anesthetic_items' => ['sometimes', 'nullable', 'array', 'max:50'],
            'anesthetic_items.*.reference_code' => [
                'required',
                'string',
                'distinct:strict',
                Rule::in(array_column(SurgeryReferenceData::anesthesiaItems(), 'code')),
            ],
            'anesthetic_items.*.details' => ['nullable', 'string', 'max:1000'],
            'anesthetic_items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:999999.99', 'decimal:0,2'],
            'anesthetic_items.*.unit' => ['nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<int, string> */
    private static function medicalConditions(): array
    {
        return [
            'HYPERTENSION', 'DIABETES', 'JAUNDICE', 'CONJUNCTIVITIS',
            'RHINITIS', 'ASTHMA', 'HEADACHE', 'SEIZURE', 'FEVER', 'VERTIGO',
            'EPIGASTRIC_PAIN', 'NAUSEA_VOMITING', 'COUGH', 'ACUTE_PAIN',
            'CHRONIC_PAIN', 'ABDOMINAL_PAIN', 'CHEST_PAIN', 'LUMBAR_PAIN',
        ];
    }
}
