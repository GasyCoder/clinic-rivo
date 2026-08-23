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

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $attributes = [
            'anesthetist_id' => 'anesthésiste',
            'notes' => 'conduite et observations',
            'administered_at' => 'date d’administration de l’anesthésie',
            'consultation_data.admission_reason' => 'motif d’entrée',
            'consultation_data.tobacco' => 'tabagisme',
            'consultation_data.alcohol' => 'consommation d’alcool',
            'consultation_data.other_toxic_exposure' => 'autre exposition toxique',
            'consultation_data.medical_conditions' => 'antécédents médicaux',
            'consultation_data.medical_conditions.*' => 'antécédent médical',
            'consultation_data.medical_history_notes' => 'précisions des antécédents médicaux',
            'consultation_data.cough_duration' => 'durée de la toux',
            'consultation_data.sputum' => 'crachats',
            'consultation_data.pain_notes' => 'description de la douleur',
            'consultation_data.anesthetic_history' => 'antécédents anesthésiques',
            'consultation_data.surgical_history' => 'antécédents chirurgicaux',
            'consultation_data.anesthetic_incidents' => 'incidents anesthésiques antérieurs',
            'consultation_data.gyneco_obstetric.gravida' => 'nombre de grossesses',
            'consultation_data.gyneco_obstetric.para' => 'nombre d’accouchements',
            'consultation_data.gyneco_obstetric.abortion' => 'nombre d’avortements',
            'consultation_data.gyneco_obstetric.last_menstrual_period' => 'date des dernières règles',
            'consultation_data.gyneco_obstetric.contraception' => 'méthode contraceptive',
            'consultation_data.gyneco_obstetric.contraception_date' => 'date de contraception',
            'consultation_data.gyneco_obstetric.delivery_route' => 'voie d’accouchement',
            'consultation_data.gyneco_obstetric.delivery_date' => 'date d’accouchement',
            'consultation_data.gyneco_obstetric.parity_status' => 'parité',
            'consultation_data.gyneco_obstetric.obstetric_hemorrhage' => 'hémorragie obstétricale',
            'consultation_data.gyneco_obstetric.notes' => 'observations gynéco-obstétricales',
            'consultation_data.clinical_exam.cardiovascular' => 'examen cardio-vasculaire',
            'consultation_data.clinical_exam.pulmonary' => 'examen pulmonaire',
            'consultation_data.clinical_exam.neurological' => 'examen neurologique',
            'consultation_data.clinical_exam.coloration' => 'coloration',
            'consultation_data.clinical_exam.venous_access' => 'abord veineux',
            'consultation_data.clinical_exam.spinal_access' => 'abord rachidien',
            'consultation_data.blood_pressure_systolic' => 'tension artérielle systolique',
            'consultation_data.blood_pressure_diastolic' => 'tension artérielle diastolique',
            'consultation_data.heart_rate' => 'fréquence cardiaque',
            'consultation_data.oxygen_saturation' => 'saturation en oxygène',
            'consultation_data.respiratory_rate' => 'fréquence respiratoire',
            'consultation_data.temperature_celsius' => 'température',
            'consultation_data.weight_kg' => 'poids',
            'consultation_data.height_cm' => 'taille',
            'consultation_data.mouth_opening' => 'ouverture buccale',
            'consultation_data.mallampati' => 'score de Mallampati',
            'consultation_data.thyromental_distance' => 'distance thyromentonnière',
            'consultation_data.cervical_spine' => 'rachis cervical',
            'consultation_data.dental_prosthesis' => 'prothèse dentaire',
            'consultation_data.other_prosthesis' => 'autre prothèse',
            'consultation_data.last_meal_time' => 'heure du dernier repas',
            'consultation_data.last_drink_time' => 'heure de la dernière boisson',
            'consultation_data.neuropsychological_status' => 'état neuropsychologique',
            'paraclinical_data.blood_group' => 'groupe sanguin',
            'paraclinical_data.rhesus' => 'rhésus',
            'paraclinical_data.transfusion_recommended_units' => 'nombre de culots recommandés',
            'paraclinical_data.transfusion_received' => 'transfusion reçue',
            'paraclinical_data.preoperative_transfusion_units' => 'nombre de culots préopératoires',
            'paraclinical_data.ultrasound_notes' => 'résultat de l’échographie',
            'paraclinical_data.ultrasonographer' => 'échographiste',
            'paraclinical_data.glasgow_eye' => 'ouverture des yeux (Glasgow)',
            'paraclinical_data.glasgow_verbal' => 'réponse verbale (Glasgow)',
            'paraclinical_data.glasgow_motor' => 'réponse motrice (Glasgow)',
            'paraclinical_data.apfel_score' => 'score d’Apfel',
            'paraclinical_data.associated_pathologies' => 'pathologies associées',
            'paraclinical_data.conclusion' => 'conclusion anesthésique',
            'paraclinical_data.therapeutic_recommendation' => 'recommandation thérapeutique',
            'paraclinical_data.surgery_authorized' => 'autorisation de la chirurgie',
            'paraclinical_data.asa_class' => 'classe ASA',
            'paraclinical_data.nyha_class' => 'classe NYHA',
            'paraclinical_data.anesthesia_plan' => 'plan anesthésique',
            'paraclinical_data.fasting_hours' => 'durée du jeûne prescrit',
            'anesthetic_items' => 'éléments d’anesthésie',
            'anesthetic_items.*.reference_code' => 'élément d’anesthésie',
            'anesthetic_items.*.details' => 'précision de l’élément d’anesthésie',
            'anesthetic_items.*.quantity' => 'quantité de l’élément d’anesthésie',
            'anesthetic_items.*.unit' => 'unité de l’élément d’anesthésie',
        ];

        $laboratoryLabels = [
            'hemoglobin' => 'hémoglobine',
            'hematocrit' => 'hématocrite',
            'psa' => 'PSA',
            'creatinine' => 'créatinine',
            'glycemia' => 'glycémie',
            'urea' => 'urée',
            'tdr' => 'TDR',
            'crp' => 'CRP',
            'widal_to' => 'Widal TO',
            'widal_th' => 'Widal TH',
        ];

        foreach ($laboratoryLabels as $field => $label) {
            $attributes["paraclinical_data.laboratory.{$field}"] = $label;
        }

        $pathologyLabels = [
            'cardiac' => 'pathologie cardiaque',
            'respiratory' => 'pathologie respiratoire',
            'renal' => 'pathologie rénale',
            'digestive' => 'pathologie digestive',
            'neurological' => 'pathologie neurologique',
            'gynecological' => 'pathologie gynécologique',
            'ent' => 'pathologie ORL',
        ];

        foreach ($pathologyLabels as $field => $label) {
            $attributes["paraclinical_data.associated_pathologies.{$field}"] = $label;
        }

        return $attributes;
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
