<?php

namespace App\Support\Documents;

use App\Enums\HospitalStayStatus;
use App\Enums\SurgicalCarePhase;
use App\Enums\SurgicalChecklistPhase;
use App\Enums\SurgicalRequestStatus;
use App\Enums\SurgicalTreatmentPhase;
use App\Models\AnesthesiaRecord;
use App\Models\SurgicalRequest;
use App\Models\SurgicalTreatmentItem;
use App\Models\User;
use App\Support\AnesthesiaAssessmentRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

/**
 * ADR-172 — le « Dossier chirurgical » de la clinique, généré depuis ce que le
 * bloc et l'anesthésie ont déjà consigné.
 *
 * Les quatre feuilles du papier — Entrée du patient au bloc, Sortie du patient
 * au bloc, Consultation pré-anesthésique, Examen paraclinique — sont relues
 * depuis le dossier du bloc (`SurgicalRequest` et ses sous-ressources) et le
 * dossier d'anesthésie. Rien n'est ressaisi, rien n'est stocké : cette classe
 * **lit**, et une case que personne n'a remplie reste vide — jamais « Non »,
 * jamais « Normal » (ADR-077).
 *
 * Chaque feuille est gardée par le droit qui possède sa donnée (ADR-116) : les
 * deux feuilles du bloc par `surgery.view`, les deux feuilles d'anesthésie par
 * `anesthesia.view`. Une feuille refusée est servie `restricted`, jamais vide —
 * un vide se lirait « rien consigné ».
 *
 * Les libellés sont résolus ici, côté serveur : la page imprimable n'interprète
 * aucun code d'enum ni aucune clé JSON.
 */
final class SurgicalDossierSheet
{
    public const SHEETS = ['entry', 'exit', 'consultation', 'paraclinical'];

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'episode:id,uuid,episode_number,patient_id',
            'episode.patient',
            'episode.patient.addressEntry:id,label',
            'episode.hospitalStays' => fn ($query) => $query->latest('admitted_at'),
            'catalogItem:id,uuid,code,name',
            'surgeon:id,name',
            'preoperativeValidatedBy:id,name',
            'dischargedBy:id,name',
            'intervention.performedBy:id,name',
            'report.author:id,name',
            'report.validator:id,name',
            'complications.reportedBy:id,name',
            'teamMembers.user:id,name',
            'careNotes.recordedBy:id,name',
            'blockEntry',
            'blockExit',
            'observations',
            'treatmentItems',
            'safetyChecklists.confirmations.confirmedBy:id,name',
            'anesthesiaRecord.anesthetist:id,name',
            'anesthesiaRecord.assessmentValidator:id,name',
            'anesthesiaRecord.validator:id,name',
            'anesthesiaRecord.clearanceDecidedBy:id,name',
            'anesthesiaRecord.clearanceConditions',
        ];
    }

    /**
     * @param  string|null  $only  une seule feuille (`entry`, `exit`, `consultation`, `paraclinical`), ou tout le dossier
     * @return array<string, mixed>
     */
    public function present(SurgicalRequest $request, User $user, ?string $only = null): array
    {
        $request->loadMissing(self::relations());

        $canSurgery = $user->can('surgery.view');
        $canAnesthesia = $user->can('anesthesia.view');
        $record = $request->anesthesiaRecord;

        $sheets = [
            'entry' => $canSurgery ? $this->entrySheet($request) : $this->restricted('entry', 'surgery.view'),
            'exit' => $canSurgery ? $this->exitSheet($request) : $this->restricted('exit', 'surgery.view'),
            'consultation' => $canAnesthesia ? $this->consultationSheet($request, $record) : $this->restricted('consultation', 'anesthesia.view'),
            'paraclinical' => $canAnesthesia ? $this->paraclinicalSheet($request, $record) : $this->restricted('paraclinical', 'anesthesia.view'),
        ];

        if ($only !== null && in_array($only, self::SHEETS, true)) {
            $sheets = [$only => $sheets[$only]];
        }

        return [
            'uuid' => $request->uuid,
            'sheet' => $only !== null && in_array($only, self::SHEETS, true) ? $only : null,
            'header' => $this->header($request, $user),
            'sheets' => array_values($sheets),
            'sheet_options' => array_map(fn (string $key) => ['key' => $key, 'title' => self::title($key)], self::SHEETS),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public static function title(string $key): string
    {
        return match ($key) {
            'entry' => 'Entrée du patient au bloc',
            'exit' => 'Sortie du patient au bloc',
            'consultation' => 'Consultation pré-anesthésique',
            'paraclinical' => 'Examen paraclinique',
            default => $key,
        };
    }

    /** @return array<string, mixed> */
    private function header(SurgicalRequest $request, User $user): array
    {
        $episode = $request->episode;
        $patient = $episode?->patient;
        $record = $request->anesthesiaRecord;
        $canStay = $user->can('hospitalization.view');
        $stay = $episode?->hospitalStays->first(fn ($stay) => $stay->status !== HospitalStayStatus::Cancelled);

        return [
            'episode' => $episode ? ['uuid' => $episode->uuid, 'episode_number' => $episode->episode_number] : null,
            'patient' => PaperPatient::present($patient),
            'procedure' => $request->procedure_name ?: $request->catalogItem?->name,
            'procedure_details' => $request->procedure_details,
            'status' => self::statusLabel($request->status),
            'scheduled_at' => $this->dateTime($request->scheduled_at),
            'operating_room' => $request->operating_room,
            'surgeon' => $request->surgeon?->name,
            // L'anesthésiste est un fait du dossier d'anesthésie ; sans le droit de le lire, il n'est pas servi.
            'anesthetist' => $user->can('anesthesia.view') ? $record?->anesthetist?->name : null,
            'anesthetist_visible' => $user->can('anesthesia.view'),
            'stay_visible' => $canStay,
            'hospitalization' => $canStay && $stay ? [
                'admitted_at' => $this->dateTime($stay->admitted_at),
                'discharged_at' => $this->dateTime($stay->discharged_at),
                'service' => $stay->service,
                'room_bed' => $stay->room_bed,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function restricted(string $key, string $permission): array
    {
        return ['key' => $key, 'title' => self::title($key), 'restricted' => $permission, 'sections' => []];
    }

    // ------------------------------------------------------------------
    // Feuille 1 — Entrée du patient au bloc
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function entrySheet(SurgicalRequest $request): array
    {
        $entry = $request->blockEntry;

        $team = $request->teamMembers
            ->map(fn ($member) => [$member->function?->label() ?? (string) $member->function, $member->user?->name])
            ->filter(fn (array $row) => $row[1] !== null)
            ->values()
            ->all();

        return $this->sheet('entry', [
            $this->section('Programmation', 'blue', [
                $this->row('Intervention prévue', $request->procedure_name ?: $request->catalogItem?->name),
                $this->row('Date et heure programmées', $this->dateTime($request->scheduled_at)),
                $this->row('Salle', $request->operating_room),
                $this->row('Consignes de préparation', $request->preparation_notes),
                $this->row('Feu vert préopératoire', $request->preoperative_validated_at
                    ? $this->by($request->preoperativeValidatedBy?->name, $request->preoperative_validated_at)
                    : null),
                $this->row('Notes préopératoires', $request->preoperative_notes),
            ]),
            $this->table('Équipe de bloc', 'blue', ['Fonction', 'Nom'], $team),
            $this->section('Constantes à l’entrée', 'green', [
                $this->row('Taille (cm)', $entry?->height_cm),
                $this->row('Poids (kg)', $entry?->weight_kg),
                $this->row('Température (°C)', $entry?->temperature_celsius),
                $this->row('Tension artérielle (mmHg)', $this->bloodPressure($entry?->blood_pressure_systolic, $entry?->blood_pressure_diastolic)),
                $this->row('Fréquence cardiaque (batt/min)', $entry?->heart_rate),
                $this->row('SpO₂ (%)', $entry?->oxygen_saturation),
            ]),
            $this->section('Préparation préopératoire', 'green', [
                $this->row('Toilette complète', $this->yesNo($entry?->full_bath_completed)),
                $this->row('Pesage réalisé', $this->yesNo($entry?->weighing_completed)),
            ]),
            $this->section('Voie veineuse', 'yellow', [
                $this->row('Nombre de VVP', $entry?->peripheral_iv_count),
                $this->row('Sérum', $entry?->serum_name),
                $this->row('Quantité', $this->quantity($entry?->serum_quantity, $entry?->serum_unit)),
            ]),
            $this->section('Sonde urinaire', 'yellow', [
                $this->row('Sonde posée', $this->yesNo($entry?->urinary_catheter_placed)),
                $this->row('Date et heure de pose', $this->dateTime($entry?->catheter_placed_at)),
                $this->row('Aspect des urines', $entry?->urine_appearance),
                $this->row('Diurèse', $this->quantity($entry?->diuresis_quantity, $entry?->diuresis_unit)),
            ]),
            $this->treatmentTable($request, SurgicalTreatmentPhase::Preliminary),
            $this->checklistSection($request, SurgicalChecklistPhase::SignIn),
            $this->checklistSection($request, SurgicalChecklistPhase::TimeOut),
            $this->notesSection($request, SurgicalCarePhase::Perioperative, 'Soins peropératoires'),
        ]);
    }

    // ------------------------------------------------------------------
    // Feuille 2 — Sortie du patient au bloc
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function exitSheet(SurgicalRequest $request): array
    {
        $exit = $request->blockExit;
        $intervention = $request->intervention;
        $report = $request->report;

        $vitals = [
            ['Tension artérielle (mmHg)', $this->bloodPressure($exit?->entry_blood_pressure_systolic, $exit?->entry_blood_pressure_diastolic), $this->bloodPressure($exit?->exit_blood_pressure_systolic, $exit?->exit_blood_pressure_diastolic)],
            ['Fréquence cardiaque (batt/min)', $exit?->entry_heart_rate, $exit?->exit_heart_rate],
            ['SpO₂ (%)', $exit?->entry_oxygen_saturation, $exit?->exit_oxygen_saturation],
            ['Fréquence respiratoire (cycles/min)', $exit?->entry_respiratory_rate, $exit?->exit_respiratory_rate],
            ['Température (°C)', $exit?->entry_temperature_celsius, $exit?->exit_temperature_celsius],
        ];

        $observations = $request->observations
            ->map(fn ($observation) => [
                $this->dateTime($observation->observed_at),
                $this->bloodPressure($observation->blood_pressure_systolic, $observation->blood_pressure_diastolic),
                $observation->heart_rate,
                $observation->oxygen_saturation,
                $observation->temperature_celsius,
                $this->quantity($observation->diuresis_quantity, $observation->diuresis_unit),
            ])
            ->all();

        $complications = $request->complications
            ->map(fn ($complication) => [
                $this->dateTime($complication->reported_at),
                $complication->description,
                $complication->reportedBy?->name,
            ])
            ->all();

        return $this->sheet('exit', [
            $this->section('Intervention', 'blue', [
                $this->row('Entrée au bloc', $this->dateTime($exit?->block_entered_at)),
                $this->row('Début de l’intervention', $this->dateTime($intervention?->started_at)),
                $this->row('Fin de l’intervention', $this->dateTime($intervention?->ended_at)),
                $this->row('Sortie du bloc', $this->dateTime($exit?->block_exited_at)),
                $this->row('Opérateur', $intervention?->performedBy?->name),
                $this->row('Résumé de l’acte', $intervention?->procedure_summary),
                $this->row('Notes', $intervention?->notes),
            ]),
            $this->table('Constantes', 'green', ['', 'À l’entrée', 'À la sortie'], $this->cleanRows($vitals)),
            $this->section('Perfusion et transfusion', 'yellow', [
                $this->row('Sérum', $exit?->perfusion_serum),
                $this->row('Poche', $exit?->perfusion_bag),
                $this->row('Transfusion — sang', $exit?->transfusion_blood),
                $this->row('Transfusion — quantité', $this->quantity($exit?->transfusion_quantity, $exit?->transfusion_unit)),
            ]),
            $this->section('Urines et pertes', 'yellow', [
                $this->row('Urines — aspect', $exit?->urine_appearance),
                $this->row('Urines — quantité', $this->quantity($exit?->urine_quantity, $exit?->urine_unit)),
                $this->row('Pertes sanguines', $this->quantity($exit?->blood_loss_quantity, $exit?->blood_loss_unit)),
            ]),
            $this->section('Drogues et antibiotiques', 'yellow', [
                $this->row('Drogue', $this->named($exit?->drug_name, $exit?->drug_quantity, $exit?->drug_unit)),
                $this->row('Antibiotique', $this->named($exit?->antibiotic_name, $exit?->antibiotic_quantity, $exit?->antibiotic_unit)),
            ]),
            $this->section('Réveil', 'green', [
                $this->row('État de réveil', $exit?->awakening_status?->label()),
                $this->row('Score de réveil', $exit?->awakening_score),
            ]),
            $this->treatmentTable($request, SurgicalTreatmentPhase::Postoperative),
            $this->table('Surveillance postopératoire', 'green', ['Date et heure', 'TA (mmHg)', 'FC', 'SpO₂ (%)', 'T° (°C)', 'Diurèse'], $observations),
            $this->table('Complications', 'yellow', ['Date', 'Description', 'Signalée par'], $complications),
            $this->notesSection($request, SurgicalCarePhase::Postoperative, 'Soins postopératoires'),
            $this->checklistSection($request, SurgicalChecklistPhase::SignOut),
            $this->section('Compte rendu opératoire', 'blue', [
                $this->row('Rédigé par', $report?->author?->name),
                $this->row('Validé', $report?->validated_at ? $this->by($report->validator?->name, $report->validated_at) : null),
                $this->row('Compte rendu', $report?->content),
            ]),
            $this->section('Sortie de Chirurgie', 'blue', [
                $this->row('Sortie prononcée', $request->discharged_at ? $this->by($request->dischargedBy?->name, $request->discharged_at) : null),
                $this->row('Observations de sortie', $request->discharge_notes),
            ]),
        ]);
    }

    // ------------------------------------------------------------------
    // Feuille 3 — Consultation pré-anesthésique
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function consultationSheet(SurgicalRequest $request, ?AnesthesiaRecord $record): array
    {
        $data = $record?->consultation_data ?? [];
        $gyneco = Arr::get($data, 'gyneco_obstetric', []) ?: [];
        $exam = Arr::get($data, 'clinical_exam', []) ?: [];

        $conditionLabels = AnesthesiaAssessmentRules::medicalConditionLabels();
        $conditions = collect(Arr::get($data, 'medical_conditions', []) ?: [])
            ->map(fn ($code) => $conditionLabels[$code] ?? (string) $code)
            ->implode(', ');

        return $this->sheet('consultation', [
            $this->section('Anesthésiste', 'blue', [
                $this->row('Anesthésiste', $record?->anesthetist?->name),
                $this->row('Évaluation validée', $record?->assessment_validated_at
                    ? $this->by($record->assessmentValidator?->name, $record->assessment_validated_at)
                    : null),
            ]),
            $this->section('Admission et habitudes', 'green', [
                $this->row('Motif d’entrée', Arr::get($data, 'admission_reason')),
                $this->row('Tabagisme', Arr::get($data, 'tobacco')),
                $this->row('Alcool', Arr::get($data, 'alcohol')),
                $this->row('Autre exposition toxique', Arr::get($data, 'other_toxic_exposure')),
                $this->row('État neuropsychologique', $this->neuroLabel(Arr::get($data, 'neuropsychological_status'))),
            ]),
            $this->section('Antécédents', 'green', [
                $this->row('Antécédents médicaux', $conditions === '' ? null : $conditions),
                $this->row('Précisions', Arr::get($data, 'medical_history_notes')),
                $this->row('Toux (durée)', Arr::get($data, 'cough_duration')),
                $this->row('Crachats', Arr::get($data, 'sputum')),
                $this->row('Douleur', Arr::get($data, 'pain_notes')),
                $this->row('Antécédents anesthésiques', Arr::get($data, 'anesthetic_history')),
                $this->row('Incidents anesthésiques antérieurs', Arr::get($data, 'anesthetic_incidents')),
                $this->row('Antécédents chirurgicaux', Arr::get($data, 'surgical_history')),
            ]),
            $this->section('Gynéco-obstétrique', 'yellow', [
                $this->row('Gestité / Parité / Avortements', $this->gpa($gyneco)),
                $this->row('Dernières règles', $this->date(Arr::get($gyneco, 'last_menstrual_period'))),
                $this->row('Contraception', $this->contraceptionLabel(Arr::get($gyneco, 'contraception'))),
                $this->row('Date de contraception', $this->date(Arr::get($gyneco, 'contraception_date'))),
                $this->row('Accouchement', $this->deliveryLabel(Arr::get($gyneco, 'delivery_route'))),
                $this->row('Date d’accouchement', $this->date(Arr::get($gyneco, 'delivery_date'))),
                $this->row('Parité', $this->parityLabel(Arr::get($gyneco, 'parity_status'))),
                $this->row('Hémorragie obstétricale', $this->yesNo(Arr::get($gyneco, 'obstetric_hemorrhage'))),
                $this->row('Observations', Arr::get($gyneco, 'notes')),
            ]),
            $this->section('Constantes', 'green', [
                $this->row('Tension artérielle (mmHg)', $this->bloodPressure(Arr::get($data, 'blood_pressure_systolic'), Arr::get($data, 'blood_pressure_diastolic'))),
                $this->row('Fréquence cardiaque (batt/min)', Arr::get($data, 'heart_rate')),
                $this->row('SpO₂ (%)', Arr::get($data, 'oxygen_saturation')),
                $this->row('Fréquence respiratoire (cycles/min)', Arr::get($data, 'respiratory_rate')),
                $this->row('Température (°C)', Arr::get($data, 'temperature_celsius')),
                $this->row('Poids (kg)', Arr::get($data, 'weight_kg')),
                $this->row('Taille (cm)', Arr::get($data, 'height_cm')),
            ]),
            $this->section('Examen par systèmes', 'green', [
                $this->row('Cardio-vasculaire', Arr::get($exam, 'cardiovascular')),
                $this->row('Pulmonaire', Arr::get($exam, 'pulmonary')),
                $this->row('Neurologique', Arr::get($exam, 'neurological')),
                $this->row('Coloration', Arr::get($exam, 'coloration')),
                $this->row('Abord veineux', Arr::get($exam, 'venous_access')),
                $this->row('Abord rachidien', Arr::get($exam, 'spinal_access')),
            ]),
            $this->section('Voies aériennes', 'yellow', [
                $this->row('Ouverture buccale', Arr::get($data, 'mouth_opening')),
                $this->row('Mallampati', Arr::get($data, 'mallampati')),
                $this->row('Distance thyromentonnière', Arr::get($data, 'thyromental_distance')),
                $this->row('Rachis cervical', Arr::get($data, 'cervical_spine')),
                $this->row('Prothèse dentaire', Arr::get($data, 'dental_prosthesis')),
                $this->row('Autre prothèse', Arr::get($data, 'other_prosthesis')),
            ]),
            $this->section('Jeûne', 'yellow', [
                $this->row('Dernier repas', Arr::get($data, 'last_meal_time')),
                $this->row('Dernière boisson', Arr::get($data, 'last_drink_time')),
            ]),
        ]);
    }

    // ------------------------------------------------------------------
    // Feuille 4 — Examen paraclinique (et conduite anesthésique)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function paraclinicalSheet(SurgicalRequest $request, ?AnesthesiaRecord $record): array
    {
        $data = $record?->paraclinical_data ?? [];
        $lab = Arr::get($data, 'laboratory', []) ?: [];
        $pathologies = Arr::get($data, 'associated_pathologies', []) ?: [];

        $glasgow = collect([Arr::get($data, 'glasgow_eye'), Arr::get($data, 'glasgow_verbal'), Arr::get($data, 'glasgow_motor')]);
        $glasgowTotal = $glasgow->every(fn ($value) => filled($value)) ? $glasgow->sum(fn ($value) => (int) $value) : null;

        $items = collect($record?->anesthetic_items ?? [])
            ->map(fn (array $item) => [
                $item['label_snapshot'] ?? $item['reference_code'] ?? null,
                $item['details'] ?? null,
                $this->quantity($item['quantity'] ?? null, $item['unit'] ?? null),
            ])
            ->all();

        $conditions = ($record?->clearanceConditions ?? collect())
            ->map(fn ($condition) => [
                $condition->label,
                $condition->isOpen() ? 'Ouverte' : 'Levée'.($condition->resolved_at ? ' le '.$this->dateTime($condition->resolved_at) : ''),
                $condition->resolution_notes,
            ])
            ->all();

        $clearanceRows = [
            $this->row('Décision anesthésique', $record?->clearance_status?->label()),
            $this->row('Décidée', $record?->clearance_decided_at
                ? $this->by($record->clearanceDecidedBy?->name, $record->clearance_decided_at)
                : null),
            $this->row('Motif', $record?->clearance_reason),
            $this->row('Valable jusqu’au', $this->dateTime($record?->clearance_valid_until)),
        ];
        // Divergence avec le papier, signalée et non masquée (ADR-020) : la case
        // « Autorisation d'opérer » du papier est désormais la décision de
        // l'ADR-170. L'ancien drapeau JSON n'est imprimé que s'il a réellement
        // été coché un jour — jamais converti en décision (ADR-074).
        $legacy = Arr::get($data, 'surgery_authorized');
        if ($legacy !== null) {
            $clearanceRows[] = $this->row('Autorisation d’opérer (ancienne saisie)', $this->yesNo($legacy));
        }

        return $this->sheet('paraclinical', [
            $this->section('Résultats de laboratoire', 'green', [
                $this->row('Hémoglobine', Arr::get($lab, 'hemoglobin')),
                $this->row('Hématocrite', Arr::get($lab, 'hematocrit')),
                $this->row('PSA', Arr::get($lab, 'psa')),
                $this->row('Créatinine', Arr::get($lab, 'creatinine')),
                $this->row('Glycémie', Arr::get($lab, 'glycemia')),
                $this->row('Urée', Arr::get($lab, 'urea')),
                $this->row('TDR', Arr::get($lab, 'tdr')),
                $this->row('CRP', Arr::get($lab, 'crp')),
                $this->row('Widal TO', Arr::get($lab, 'widal_to')),
                $this->row('Widal TH', Arr::get($lab, 'widal_th')),
            ]),
            $this->section('Groupe sanguin et transfusion', 'green', [
                $this->row('Groupe sanguin', $this->bloodGroup(Arr::get($data, 'blood_group'), Arr::get($data, 'rhesus'))),
                $this->row('Culots recommandés', Arr::get($data, 'transfusion_recommended_units')),
                $this->row('Transfusion reçue', $this->yesNo(Arr::get($data, 'transfusion_received'))),
                $this->row('Culots préopératoires', Arr::get($data, 'preoperative_transfusion_units')),
            ]),
            $this->section('Échographie', 'yellow', [
                $this->row('Résultat', Arr::get($data, 'ultrasound_notes')),
                $this->row('Échographiste', Arr::get($data, 'ultrasonographer')),
            ]),
            $this->section('Scores', 'yellow', [
                $this->row('Glasgow — yeux / verbal / moteur', $glasgow->every(fn ($value) => blank($value)) ? null : $glasgow->map(fn ($value) => filled($value) ? $value : '—')->implode(' / ')),
                $this->row('Glasgow — total', $glasgowTotal),
                $this->row('Score d’Apfel', Arr::get($data, 'apfel_score')),
                $this->row('Classe ASA', Arr::get($data, 'asa_class')),
                $this->row('Classe NYHA', Arr::get($data, 'nyha_class')),
            ]),
            $this->section('Pathologies associées', 'green', [
                $this->row('Cardiaque', Arr::get($pathologies, 'cardiac')),
                $this->row('Respiratoire', Arr::get($pathologies, 'respiratory')),
                $this->row('Rénale', Arr::get($pathologies, 'renal')),
                $this->row('Digestive', Arr::get($pathologies, 'digestive')),
                $this->row('Neurologique', Arr::get($pathologies, 'neurological')),
                $this->row('Gynécologique', Arr::get($pathologies, 'gynecological')),
                $this->row('ORL', Arr::get($pathologies, 'ent')),
            ]),
            $this->section('Conclusion et plan', 'blue', [
                $this->row('Conclusion', Arr::get($data, 'conclusion')),
                $this->row('Recommandation thérapeutique', Arr::get($data, 'therapeutic_recommendation')),
                $this->row('Plan anesthésique', Arr::get($data, 'anesthesia_plan')),
                $this->row('Jeûne prescrit (heures)', Arr::get($data, 'fasting_hours')),
            ]),
            $this->section('Autorisation de passage au bloc', 'blue', $clearanceRows),
            $this->table('Conditions de l’autorisation', 'blue', ['Condition', 'État', 'Notes'], $conditions),
            $this->table('Conduite anesthésique', 'yellow', ['Élément', 'Précision', 'Quantité'], $items),
            $this->section('Conduite et observations', 'yellow', [
                $this->row('Anesthésie administrée le', $this->dateTime($record?->administered_at)),
                $this->row('Observations', $record?->notes),
                $this->row('Dossier d’anesthésie finalisé', $record?->validated_at
                    ? $this->by($record->validator?->name, $record->validated_at)
                    : null),
            ]),
        ]);
    }

    // ------------------------------------------------------------------
    // Sections réutilisées
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function treatmentTable(SurgicalRequest $request, SurgicalTreatmentPhase $phase): array
    {
        $rows = $request->treatmentItems
            ->filter(fn (SurgicalTreatmentItem $item) => $item->phase === $phase)
            ->map(fn (SurgicalTreatmentItem $item) => [
                $item->category?->label(),
                $item->label,
                $this->quantity($item->quantity, $item->unit),
            ])
            ->values()
            ->all();

        return $this->table($phase->label(), 'yellow', ['Catégorie', 'Désignation', 'Quantité'], $rows);
    }

    /** @return array<string, mixed> */
    private function notesSection(SurgicalRequest $request, SurgicalCarePhase $phase, string $title): array
    {
        $rows = $request->careNotes
            ->filter(fn ($note) => $note->phase === $phase)
            ->map(fn ($note) => [$this->dateTime($note->recorded_at), $note->note, $note->recordedBy?->name])
            ->values()
            ->all();

        return $this->table($title, 'green', ['Date et heure', 'Note', 'Visa'], $rows);
    }

    /**
     * La checklist n'est imprimée que par ce qu'elle a réellement enregistré : un
     * temps sans confirmation reste une ligne vide, jamais « fait » (ADR-170).
     *
     * @return array<string, mixed>
     */
    private function checklistSection(SurgicalRequest $request, SurgicalChecklistPhase $phase): array
    {
        $checklist = $request->safetyChecklists->first(fn ($item) => $item->phase === $phase);

        $confirmations = ($checklist?->confirmations ?? collect())
            ->map(fn ($confirmation) => trim(sprintf(
                '%s — %s%s',
                $confirmation->role?->label() ?? (string) $confirmation->role,
                $confirmation->confirmedBy?->name ?? '',
                $confirmation->confirmed_at ? ' le '.$this->dateTime($confirmation->confirmed_at) : '',
            )))
            ->implode(' ; ');

        return $this->section('Checklist de sécurité — '.$phase->label(), 'blue', [
            $this->row('Terminée le', $this->dateTime($checklist?->completed_at)),
            $this->row('Confirmations', $confirmations === '' ? null : $confirmations),
        ]);
    }

    // ------------------------------------------------------------------
    // Briques
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function sheet(string $key, array $sections): array
    {
        return ['key' => $key, 'title' => self::title($key), 'restricted' => null, 'sections' => array_values($sections)];
    }

    /**
     * @param  list<array{label: string, value: string|null}>  $rows
     * @return array<string, mixed>
     */
    private function section(string $title, string $tone, array $rows): array
    {
        return ['type' => 'rows', 'title' => $title, 'tone' => $tone, 'rows' => array_values($rows)];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @return array<string, mixed>
     */
    private function table(string $title, string $tone, array $headers, array $rows): array
    {
        return [
            'type' => 'table',
            'title' => $title,
            'tone' => $tone,
            'headers' => $headers,
            'rows' => array_map(fn (array $row) => array_map(fn ($cell) => $this->text($cell), $row), $rows),
        ];
    }

    /** @return array{label: string, value: string|null} */
    private function row(string $label, mixed $value): array
    {
        return ['label' => $label, 'value' => $this->text($value)];
    }

    /** Un tableau de constantes dont aucune cellule n'est remplie ne mérite pas ses lignes vides. */
    private function cleanRows(array $rows): array
    {
        return collect($rows)
            ->filter(fn (array $row) => collect(array_slice($row, 1))->contains(fn ($cell) => filled($cell)))
            ->values()
            ->all();
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }
        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }
        if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
            $string = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

            return str_replace('.', ',', $string);
        }

        return trim((string) $value);
    }

    private function yesNo(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ? 'Oui' : 'Non';
    }

    private function bloodPressure(mixed $systolic, mixed $diastolic): ?string
    {
        if (blank($systolic) && blank($diastolic)) {
            return null;
        }

        return sprintf('%s / %s', filled($systolic) ? $systolic : '—', filled($diastolic) ? $diastolic : '—');
    }

    private function quantity(mixed $quantity, ?string $unit): ?string
    {
        $text = $this->text($quantity);
        if ($text === null) {
            return null;
        }

        return trim($text.' '.($unit ?? ''));
    }

    private function named(?string $name, mixed $quantity, ?string $unit): ?string
    {
        $quantity = $this->quantity($quantity, $unit);
        if (blank($name)) {
            return $quantity;
        }

        return trim($name.($quantity ? ' — '.$quantity : ''));
    }

    private function by(?string $name, CarbonInterface|string|null $at): ?string
    {
        $date = $this->dateTime($at);
        if ($name === null && $date === null) {
            return null;
        }

        return trim(($name ?? '').($date ? ' le '.$date : ''));
    }

    private function dateTime(CarbonInterface|string|null $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        $date = $value instanceof CarbonInterface ? $value : CarbonImmutable::parse($value);

        return $date->format('d/m/Y H:i');
    }

    private function date(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse($value)->format('d/m/Y');
    }

    private function gpa(array $gyneco): ?string
    {
        $parts = [Arr::get($gyneco, 'gravida'), Arr::get($gyneco, 'para'), Arr::get($gyneco, 'abortion')];
        if (collect($parts)->every(fn ($value) => blank($value))) {
            return null;
        }

        return sprintf('G%s P%s A%s', $parts[0] ?? '—', $parts[1] ?? '—', $parts[2] ?? '—');
    }

    private function bloodGroup(?string $group, ?string $rhesus): ?string
    {
        if (blank($group) && blank($rhesus)) {
            return null;
        }
        $sign = match ($rhesus) {
            'POSITIVE' => '+', 'NEGATIVE' => '−', default => ''
        };

        return trim(($group ?? '').' '.$sign.($rhesus && ! $group ? ' (Rhésus '.($rhesus === 'POSITIVE' ? 'positif' : 'négatif').')' : ''));
    }

    private function neuroLabel(?string $value): ?string
    {
        return match ($value) {
            'CALM' => 'Calme', 'RELAXED' => 'Détendu(e)', 'ANXIOUS' => 'Anxieux(se)', 'AGITATED' => 'Agité(e)',
            default => $value ?: null,
        };
    }

    private function contraceptionLabel(?string $value): ?string
    {
        return match ($value) {
            'ORAL' => 'Orale', 'INJECTION' => 'Injection', default => $value ?: null
        };
    }

    private function deliveryLabel(?string $value): ?string
    {
        return match ($value) {
            'VAGINAL' => 'Voie basse', 'CESAREAN' => 'Césarienne', default => $value ?: null
        };
    }

    private function parityLabel(?string $value): ?string
    {
        return match ($value) {
            'PRIMIPAROUS' => 'Primipare', 'MULTIPAROUS' => 'Multipare', default => $value ?: null
        };
    }

    public static function statusLabel(?SurgicalRequestStatus $status): ?string
    {
        return match ($status) {
            SurgicalRequestStatus::Pending => 'À programmer',
            SurgicalRequestStatus::Scheduled => 'Programmée',
            SurgicalRequestStatus::PreoperativeValidated => 'Prête pour le bloc',
            SurgicalRequestStatus::InProgress => 'Au bloc',
            SurgicalRequestStatus::Completed => 'Opéré',
            SurgicalRequestStatus::Discharged => 'Sorti du bloc',
            SurgicalRequestStatus::Cancelled => 'Annulée',
            default => null,
        };
    }
}
