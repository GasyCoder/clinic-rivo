<?php

namespace App\Support\Documents;

use App\Enums\HospitalStayStatus;
use App\Enums\PatientAntecedentType;
use App\Models\Consultation;
use App\Models\Episode;
use App\Models\User;
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
 */
final class MedicalRecordSheet
{
    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /** @return array<string, mixed> */
    public function present(Episode $episode, User $user): array
    {
        $episode->loadMissing([
            'patient.addressEntry:id,label',
            'patient.allergies' => fn ($query) => $query->orderBy('substance'),
            'patient.antecedents' => fn ($query) => $query->orderBy('created_at'),
            'careRecord',
            'hospitalStays' => fn ($query) => $query->latest('admitted_at'),
            'hospitalStays.hospitalizationRequest:id,reason',
            'medicalDischarge',
        ]);

        $canVitals = $user->can('vitals.view');
        $canHistory = $user->can('patients.medical_history.view');
        $care = $episode->careRecord;

        $consultations = Consultation::query()
            ->where('episode_id', $episode->getKey())
            ->with([
                'diagnoses' => fn ($query) => $query->whereDoesntHave('cancellation')->orderBy('created_at'),
                'currentTreatments' => fn ($query) => $query->orderBy('position'),
            ])
            ->orderBy('created_at')
            ->get();

        $stay = $episode->hospitalStays->first(fn ($stay) => $stay->status !== HospitalStayStatus::Cancelled);

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
            'episode' => [
                'uuid' => $episode->uuid,
                'episode_number' => $episode->episode_number,
            ],
            'patient' => PaperPatient::present($episode->patient),
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
                ? $episode->patient?->allergies->pluck('substance')->filter()->values() ?? []
                : [],
            'familial_antecedents' => $canHistory
                ? $episode->patient?->antecedents
                    ->filter(fn ($antecedent) => $antecedent->type === PatientAntecedentType::Familial)
                    ->pluck('description')->filter()->values() ?? []
                : [],
            'current_treatments' => $treatments,
            'hospitalization' => $stay ? [
                'reason' => $this->richText->plainText($stay->hospitalizationRequest?->reason) ?: null,
                'admitted_at' => $stay->admitted_at?->toDateString(),
                'discharged_at' => $stay->discharged_at?->toDateString(),
            ] : null,
            // Un diagnostic posé en consultation d'abord ; à défaut, celui de
            // la sortie médicale. Aucun n'est inventé.
            'diagnosis' => $diagnoses->isNotEmpty()
                ? $diagnoses->implode(' ; ')
                : ($episode->medicalDischarge?->final_diagnosis ?: null),
        ];
    }
}
