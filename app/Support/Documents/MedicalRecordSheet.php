<?php

namespace App\Support\Documents;

use App\Enums\EpisodeStatus;
use App\Enums\HospitalStayStatus;
use App\Enums\PatientAntecedentType;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\MaternityRecord;
use App\Models\Patient;
use App\Models\User;
use App\Support\NewbornFiche;
use Carbon\CarbonImmutable;
use App\Services\Medicine\ClinicalRichTextSanitizer;

/**
 * ADR-116 — le « DOSSIER MÉDICAL » de la clinique, rempli depuis le passage.
 *
 * Chaque case de la feuille papier a déjà son domicile dans l'application
 * (ADR-074, ADR-113) : identité au dossier patient, constantes à la fiche
 * Soins, motif et dates d'hospitalisation au séjour, diagnostic en
 * consultation, traitements actuels déclarés à l'interrogatoire,
 * antécédents familiaux au dossier permanent. Rien n'est ressaisi, et une
 * case que personne n'a remplie reste vide — jamais « Non », jamais
 * « Normal ».
 *
 * Les sections gardées ailleurs par une permission le restent ici : une
 * feuille imprimée n'est pas un moyen de contourner `vitals.view`.
 *
 * ADR-143 : quand la patiente a été prise en charge en Maternité, sa section
 * (grossesse, travail, accouchement, actes, et chaque nouveau-né) s'ajoute à
 * la même feuille — un dossier, pas deux.
 *
 * ADR-145 : la même feuille se lit aussi pour un patient sans passage — un nouveau-né —,
 * et la mère et ses bébés se retrouvent par onglets sur le même modèle.
 */
final class MedicalRecordSheet
{
    public function __construct(
        private readonly ClinicalRichTextSanitizer $richText,
        private readonly MaternitySheetSection $maternity,
    ) {}

    /**
     * Le dossier médical d'un passage.
     *
     * @return array<string, mixed>
     */
    public function present(Episode $episode, User $user): array
    {
        $episode->loadMissing('patient');

        return $this->build($episode->patient, $episode, $user, [
            'href' => "/passages/{$episode->uuid}",
            'label' => 'Retour au passage',
        ]);
    }

    /**
     * Le dossier médical d'un patient, sans passage requis (ADR-145).
     *
     * Un nouveau-né dont la Réception n'a pas encore ouvert de passage n'aurait sinon aucun dossier
     * médical : la feuille est attachée à un passage. Elle lit alors le passage le plus récent quand il
     * en existe un — les constantes et diagnostics y vivent —, et sinon l'identité, les allergies et
     * antécédents permanents du patient, et sa naissance : les cases sans donnée restent vides.
     *
     * @return array<string, mixed>
     */
    public function presentForPatient(Patient $patient, User $user): array
    {
        $episode = $patient->episodes()
            ->where('status', '!=', EpisodeStatus::Cancelled->value)
            ->latest('started_at')
            ->first();

        return $this->build($patient, $episode, $user, [
            'href' => "/patients/{$patient->uuid}",
            'label' => 'Retour au dossier patient',
        ]);
    }

    /**
     * Le dossier médical d'un bébé qui n'est pas encore patient (ADR-146) : lu depuis sa fiche, chez sa mère.
     *
     * Le bébé vit d'abord dans le dossier de sa mère ; son dossier s'ouvre dès qu'il est consigné, sans attendre
     * que la Réception l'accueille. Même feuille de nouveau-né que celle d'un bébé patient : seuls changent le
     * numéro de dossier — il n'existe pas encore — et l'identité, lue sur la fiche. Rien n'est deviné : une case
     * que la fiche ne porte pas reste vide.
     *
     * @return array<string, mixed>
     */
    public function presentForNewborn(Episode $motherEpisode, MaternityRecord $record, string $newbornUuid, User $user): array
    {
        $mother = $motherEpisode->patient;
        $entries = collect($record->newborn_data['newborns'] ?? []);
        $index = $entries->search(fn ($entry) => is_array($entry) && ($entry['uuid'] ?? null) === $newbornUuid);

        abort_if($index === false, 404);

        $newborn = $entries[$index];
        $rank = $index + 1;
        $bornAt = $record->delivery_data['occurred_at'] ?? null;
        $birthDate = filled($bornAt) ? CarbonImmutable::parse($bornAt) : null;
        $name = NewbornFiche::displayName($newborn, $mother?->last_name, $rank);
        $last = trim((string) ($newborn['last_name'] ?? '')) ?: (string) $mother?->last_name;

        return [
            'episode' => null,
            'back' => ['href' => "/passages/{$motherEpisode->uuid}", 'label' => 'Retour au passage de la mère'],
            'patient' => [
                'patient_number' => null,
                'first_name' => $newborn['first_name'] ?? null,
                'last_name' => $last ?: null,
                'name' => $name,
                'birth_date' => $birthDate?->toDateString(),
                'birth_date_is_approximate' => false,
                'age' => $birthDate?->age,
                'birth_place' => null,
                'sex' => in_array($newborn['sex'] ?? null, ['M', 'F'], true) ? $newborn['sex'] : null,
                'marital_status' => null,
                'children_count' => null,
                'profession' => null,
                'address' => null,
                'phone' => null,
                'uuid' => null,
            ],
            'vitals_visible' => $user->can('vitals.view'),
            'vitals' => null,
            'history_visible' => $user->can('patients.medical_history.view'),
            'allergies' => [],
            'familial_antecedents' => [],
            'record_visible' => $user->can('medical_record.view'),
            'current_treatments' => [],
            'stay_visible' => $user->can('hospitalization.view'),
            'hospitalization' => null,
            'diagnosis_visible' => $user->can('diagnoses.view'),
            'diagnosis' => null,
            'maternity' => null,
            'birth' => $this->maternity->birthOf($record, $newbornUuid, $rank, $mother, $user),
            'dossiers' => $this->maternity->dossiersForNewborn($record, $newbornUuid, $user),
        ];
    }

    /**
     * @param  array{href: string, label: string}  $back
     * @return array<string, mixed>
     */
    private function build(Patient $patient, ?Episode $episode, User $user, array $back): array
    {
        $patient->loadMissing([
            'addressEntry:id,label',
            'allergies' => fn ($query) => $query->orderBy('substance'),
            'antecedents' => fn ($query) => $query->orderBy('created_at'),
        ]);
        $episode?->loadMissing([
            'careRecord',
            'maternityRecord',
            'hospitalStays' => fn ($query) => $query->latest('admitted_at'),
            'hospitalStays.hospitalizationRequest:id,reason',
            'medicalDischarge',
        ]);

        $canVitals = $user->can('vitals.view');
        $canHistory = $user->can('patients.medical_history.view');
        // Une feuille imprimée n'est pas un moyen de contourner un droit : chaque case est gardée par la
        // permission qui possède sa donnée, comme la page « Détail du passage » (ADR-054, ADR-116). Sans
        // cela, la Réception — qui n'a que `patients.view` — lisait ici un diagnostic qu'elle ne peut voir
        // nulle part ailleurs.
        $canDiagnoses = $user->can('diagnoses.view');
        $canRecord = $user->can('medical_record.view');
        $canStay = $user->can('hospitalization.view');
        $care = $episode?->careRecord;

        $consultations = $episode
            ? Consultation::query()
                ->where('episode_id', $episode->getKey())
                ->with([
                    'diagnoses' => fn ($query) => $query->whereDoesntHave('cancellation')->orderBy('created_at'),
                    'currentTreatments' => fn ($query) => $query->orderBy('position'),
                ])
                ->orderBy('created_at')
                ->get()
            : collect();

        $stay = $episode?->hospitalStays->first(fn ($stay) => $stay->status !== HospitalStayStatus::Cancelled);

        $diagnoses = $consultations->flatMap->diagnoses->pluck('description')->filter()->unique()->values();

        // Les traitements actuels sont ceux déclarés à la dernière
        // consultation : ce qui était vrai à cette rencontre (ADR-074).
        $treatments = $consultations->reverse()->first(fn ($consultation) => $consultation->currentTreatments->isNotEmpty())
            ?->currentTreatments
            ->map(fn ($treatment) => collect([
                $treatment->medication_name,
                $treatment->dosage,
                $treatment->frequency,
                $treatment->duration,
            ])->filter()->implode(' · '))
            ->values() ?? collect();

        return [
            'episode' => $episode ? [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
            ] : null,
            'back' => $back,
            'patient' => PaperPatient::present($patient) + ['uuid' => $patient->uuid],
            'vitals_visible' => $canVitals,
            'vitals' => $canVitals && $care ? [
                'blood_group' => $care->blood_group,
                'height_cm' => $care->height_cm,
                'weight_kg' => $care->weight_kg,
                'bmi' => $care->bmi,
                'smoker' => $care->smoker === null ? null : (bool) $care->smoker,
                'transmission_reason' => $care->transmission_reason,
                'transmission_reason_html' => $care->transmission_reason_html,
            ] : null,
            'history_visible' => $canHistory,
            'allergies' => $canHistory
                ? $patient->allergies->pluck('substance')->filter()->values() ?? []
                : [],
            'familial_antecedents' => $canHistory
                ? $patient->antecedents
                    ->filter(fn ($antecedent) => $antecedent->type === PatientAntecedentType::Familial)
                    ->pluck('description')->filter()->values() ?? []
                : [],
            'record_visible' => $canRecord,
            'current_treatments' => $canRecord ? $treatments : collect(),
            'stay_visible' => $canStay,
            'hospitalization' => $canStay && $stay ? [
                'reason' => $this->richText->plainText($stay->hospitalizationRequest?->reason) ?: null,
                'admitted_at' => $stay->admitted_at?->toDateString(),
                'discharged_at' => $stay->discharged_at?->toDateString(),
            ] : null,
            'diagnosis_visible' => $canDiagnoses,
            // Un diagnostic posé en consultation d'abord ; à défaut, celui de
            // la sortie médicale. Aucun n'est inventé.
            'diagnosis' => $canDiagnoses
                ? ($diagnoses->isNotEmpty()
                    ? $diagnoses->implode(' ; ')
                    : ($episode?->medicalDischarge?->final_diagnosis ?: null))
                : null,
            // ADR-143 : la Maternité fait partie du même dossier, jamais d'un second.
            'maternity' => $episode ? $this->maternity->present($episode, $user) : null,
            // ADR-144 : le patient est un nouveau-né de la clinique — sa naissance, lue chez sa mère.
            'birth' => $this->maternity->birth($patient, $user),
            // ADR-145 : la mère et ses bébés, chacun avec son dossier médical — un seul modèle, des onglets.
            'dossiers' => $this->maternity->dossiers($patient, $episode, $user),
        ];
    }
}
