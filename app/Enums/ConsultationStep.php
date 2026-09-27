<?php

namespace App\Enums;

/**
 * The steps of a Médecine consultation. Values match the URL segment
 * already whitelisted on `medicine.orientations.step` — the route, the
 * persisted step status and the stepper therefore all speak one vocabulary
 * rather than three that can drift apart.
 *
 * Two cases are no longer stops in the pathway but are kept so that rows
 * recorded before they left still read back: `Diagnosis` (ADR-081) and
 * `Decision` (ADR-084). Leurs URLs restent acceptées et redirigent vers
 * l'écran qui porte désormais la fonction ; aucun code nouveau ne doit les
 * viser comme destination.
 *
 * Où se fait quoi, une fois pour toutes :
 *
 *   Examen clinique       constatations et observations cliniques
 *   (hypothèses)          raisonnement provisoire, possible pendant l'évaluation
 *   Décision & clôture    conclusion du passage : diagnostic retenu,
 *                         conduite à tenir, puis clôture éventuelle
 */
enum ConsultationStep: string
{
    case Dossier = 'dossier';
    case Interview = 'consultation';
    case ClinicalExam = 'examen';
    case Paraclinical = 'paraclinique';
    case Diagnosis = 'diagnostic';
    case Prescription = 'ordonnance';
    case Decision = 'decision';
    case Closure = 'cloture';

    public function label(): string
    {
        return match ($this) {
            self::Dossier => 'Dossier du passage',
            self::Interview => 'Interrogatoire',
            self::ClinicalExam => 'Examen clinique',
            self::Paraclinical => 'Examens paracliniques',
            self::Diagnosis => 'Diagnostic',
            self::Prescription => 'Prescription',
            self::Decision => 'Décision médicale',
            self::Closure => 'Décision & clôture',
        };
    }

    /**
     * Whether the step is still a stop in the wizard.
     *
     * Ni `Diagnosis` ni `Decision` n'en sont : le diagnostic retenu et la
     * conduite à tenir se concluent tous deux à « Décision & clôture »
     * (ADR-081, ADR-084, ADR-089). Les deux cas sont conservés — des lignes
     * `consultation_steps` enregistrées avant ces changements doivent
     * continuer à se lire — ils ne figurent simplement plus au parcours.
     */
    public function isWizardStep(): bool
    {
        return ! in_array($this, [self::Diagnosis, self::Decision], true);
    }

    /** @return array<int, self> */
    public static function wizardCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $step): bool => $step->isWizardStep()));
    }

    /**
     * A step the doctor may legitimately declare "not needed for this
     * patient" — never one that can simply be left blank and still count
     * as done. Paraclinical and Prescription are optional by clinical
     * nature: many encounters require neither.
     */
    public function isSkippable(): bool
    {
        return in_array($this, [self::Paraclinical, self::Prescription], true);
    }

    /**
     * Dossier carries no entry of its own: it is a reading step. Marking it
     * completed records that the doctor took cognisance of the file, which
     * is why it has no minimum-content rule to satisfy.
     */
    public function requiresContent(): bool
    {
        return $this !== self::Dossier;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
