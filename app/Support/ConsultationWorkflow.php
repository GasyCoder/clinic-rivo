<?php

namespace App\Support;

use App\Enums\CatalogModule;
use App\Enums\ConsultationStep;
use App\Enums\ConsultationStepStatus;
use App\Enums\ReceptionRoutingMode;
use App\Models\Consultation;
use App\Models\ConsultationOrientation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Single source of truth for what a Médecine consultation still owes.
 *
 * The browser used to answer this by itself, deriving "done" from whatever
 * data was present. Two things that matters clinically cannot be derived
 * that way: a step the doctor declared unnecessary is not a step performed,
 * and an encounter is not closeable just because some rows exist. Both live
 * here, server-side, and the stepper only renders what this returns.
 */
class ConsultationWorkflow
{
    /**
     * A patient who came only for an ECG, an ultrasound or a lab test has
     * no interrogation and no physical examination to record: the doctor
     * reads the requested exam and concludes. Mirrors the rule the wizard
     * already applied — now decided once, server-side.
     */
    private const PARACLINICAL_MODULES = [CatalogModule::Imaging, CatalogModule::Laboratory];

    /**
     * Ordered steps with their real status. Missing rows read as
     * NOT_STARTED: absence of a decision is never a decision.
     *
     * @return Collection<int, array{step: ConsultationStep, status: ConsultationStepStatus, relevant: bool, skippable: bool, completed_at: ?Carbon, completed_by: ?string, skip_reason: ?string}>
     */
    public function steps(Consultation $consultation): Collection
    {
        $recorded = $consultation->relationLoaded('steps')
            ? $consultation->steps
            : $consultation->steps()->with('completedBy:id,name')->get();
        $byStep = $recorded->keyBy(fn ($row) => $row->step->value);

        return collect(ConsultationStep::wizardCases())->map(function (ConsultationStep $step) use ($consultation, $byStep): array {
            $row = $byStep->get($step->value);

            return [
                'step' => $step,
                'status' => $row?->status ?? ConsultationStepStatus::NotStarted,
                'relevant' => $this->isRelevant($consultation, $step),
                'skippable' => $step->isSkippable(),
                'completed_at' => $row?->completed_at,
                'completed_by' => $row?->completedBy?->name,
                'skip_reason' => $row?->skip_reason,
            ];
        });
    }

    /**
     * Whether this step has anything to do for this particular patient.
     * A non-relevant step is never required for closure and is never shown
     * as an omission — but it stays reachable: the rule is a shortcut, not
     * a lock, and an encounter that turns into a real consultation can
     * still be documented.
     */
    public function isRelevant(Consultation $consultation, ConsultationStep $step): bool
    {
        if (! in_array($step, [ConsultationStep::Interview, ConsultationStep::ClinicalExam], true)) {
            return true;
        }

        return ! $this->isParaclinicalOnly($consultation);
    }

    /**
     * Whether this encounter owes a final diagnosis.
     *
     * CDC §33.1 lists "diagnostic final" among what the doctor records at
     * medical discharge, and ADR-081 moved that guarantee onto the clinical
     * fact itself. It stays the rule for every real consultation.
     *
     * A paraclinical-only passage is the one case where it cannot be one
     * (ADR-094). The patient came for an ECG, an ultrasound or a lab test;
     * the conclusion of that exam *is* the diagnosis, and the exam often has
     * no result yet when the doctor closes. Demanding one there asks for a
     * conclusion drawn from nothing — precisely what ADR-076 refuses when it
     * states that requiring what does not concern every encounter
     * "pousserait à fabriquer des actes".
     *
     * Public because two guards need the same answer — the closure blocker
     * below and `StoreMedicalDischargeRequest`. Recopied, they would drift,
     * and one would demand what the other waives.
     */
    public function requiresFinalDiagnosis(Consultation $consultation): bool
    {
        return ! $this->isParaclinicalOnly($consultation);
    }

    /**
     * What still blocks this step from being validated, or null when it can
     * be. Each rule restates a fact already enforced by the step's own
     * FormRequest — stated here so the stepper can explain itself before
     * the doctor submits, never as the only guard.
     */
    public function blockerFor(Consultation $consultation, ConsultationStep $step): ?string
    {
        return match ($step) {
            ConsultationStep::Dossier => null,
            ConsultationStep::Interview => filled($consultation->reason)
                ? null
                : 'Renseignez l’interrogatoire avant de valider cette étape.',
            ConsultationStep::ClinicalExam => $this->hasClinicalExamination($consultation)
                ? null
                : 'Renseignez l’état général ou examinez au moins un appareil avant de valider cette étape.',
            ConsultationStep::Paraclinical => $this->hasParaclinicalRequest($consultation)
                ? null
                : 'Aucun examen complémentaire demandé : utilisez « Aucun examen nécessaire » pour passer cette étape.',
            ConsultationStep::Diagnosis => $this->hasActiveDiagnosis($consultation)
                ? null
                : 'Enregistrez au moins un diagnostic ou une hypothèse avant de valider cette étape.',
            ConsultationStep::Prescription => $consultation->prescriptions()->exists()
                ? null
                : 'Aucune prescription enregistrée : utilisez « Aucune prescription nécessaire » pour passer cette étape.',
            // Kept for consultations recorded before ADR-084: the case still
            // exists so old `consultation_steps` rows read back, but the step
            // is no longer part of the pathway.
            ConsultationStep::Decision => $this->hasSubmittedOrientation($consultation)
                ? null
                : 'Enregistrez la décision médicale (sortie, orientation ou demande) avant de clôturer.',
            ConsultationStep::Closure => $this->closureBlocker($consultation),
        };
    }

    /**
     * What the last step checks before it lets the doctor close.
     *
     * Selecting a destination is not transmitting a request: a consultation
     * whose orientation still reads "à configurer" has told Chirurgie,
     * Maternité or the ward nothing at all, and closing on that would leave
     * a patient oriented nowhere.
     */
    private function closureBlocker(Consultation $consultation): ?string
    {
        $orientation = $this->activeOrientation($consultation);

        if (! $orientation) {
            // ADR-098 — decided in one place only, the last step.
            return 'Conduite à tenir : indiquez la suite de la prise en charge (étape Décision & clôture).';
        }

        if (! $orientation->isSubmitted()) {
            return sprintf(
                'Orientation « %s » choisie mais non transmise : complétez sa demande avant de clôturer.',
                $orientation->type->label(),
            );
        }

        return null;
    }

    /**
     * The step to land on after resolving this one — the next relevant one,
     * or null at the end of the pathway. Skipping an irrelevant step is the
     * shortcut an ECG-only patient needs: from the file straight to the
     * requested exam, without a hollow interrogation in between.
     */
    public function nextStepAfter(Consultation $consultation, ConsultationStep $step): ?ConsultationStep
    {
        // Wizard steps only: Diagnostic left the pathway (ADR-081) and must
        // never be proposed as "the next screen".
        $cases = ConsultationStep::wizardCases();
        $index = array_search($step, $cases, true);

        if ($index === false) {
            return null;
        }

        foreach (array_slice($cases, $index + 1) as $candidate) {
            if ($this->isRelevant($consultation, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What still prevents closing the consultation.
     *
     * Deliberately does NOT require a laboratory request, an imaging
     * request, a prescription or a hospitalisation: none of these concerns
     * every encounter. Optional steps only have to be resolved — carried
     * out or explicitly declared unnecessary.
     *
     * @return array<int, string>
     */
    public function blockersForClosure(Consultation $consultation): array
    {
        $blockers = $this->steps($consultation)
            // Closure is excluded on purpose: closing the consultation IS
            // resolving that step, and listing it here would ask the doctor
            // to validate the very action they are performing.
            ->reject(fn (array $entry): bool => $entry['step'] === ConsultationStep::Closure)
            ->filter(fn (array $entry): bool => $entry['relevant'] && ! $entry['status']->isResolved())
            ->map(fn (array $entry): array => [
                'message' => sprintf(
                    '%s : %s',
                    $entry['step']->label(),
                    $entry['step']->isSkippable()
                        ? 'à valider ou à déclarer non nécessaire.'
                        : 'à valider avant la clôture.',
                ),
                // L'étape à rejoindre. L'ADR-084 promet « ce qui manque
                // encore **et le chemin pour y retourner** » ; sans elle,
                // l'écran énonçait l'obstacle et laissait le médecin le
                // chercher.
                'step' => $entry['step']->value,
            ])
            ->values()
            ->all();

        // The diagnosis no longer has a step of its own, but a consultation
        // still cannot close without a clinical conclusion. The requirement
        // moved support; it did not disappear.
        if ($this->requiresFinalDiagnosis($consultation) && ! $this->hasActiveDiagnosis($consultation)) {
            // ADR-098 — every patient, with or without a clinical
            // examination, concludes on the same step.
            //
            // ADR-095 — a doctor who answered "pas maintenant" is waiting for
            // something, not forgetting. Saying "aucun diagnostic enregistré"
            // to them would read as an oversight, and the distinction between
            // a deliberate deferral and a blank is the one this dossier keeps
            // everywhere else.
            $blockers[] = [
                'message' => $this->diagnosisDeferred($consultation)
                    ? 'Diagnostic : différé par le médecin — enregistrez-le à l’étape Décision & clôture pour pouvoir clôturer.'
                    : 'Diagnostic : aucun diagnostic enregistré — posez-le à l’étape Décision & clôture.',
                // Déjà sur place : le diagnostic se pose à la Clôture.
                'step' => null,
            ];
        }

        // Same for the conduite à tenir: the "Décision" step is gone, the
        // obligation to say where the patient goes is not (ADR-084).
        if ($blocker = $this->closureBlocker($consultation)) {
            // Également sur place : la conduite à tenir se choisit ici.
            $blockers[] = ['message' => $blocker, 'step' => null];
        }

        return $blockers;
    }

    /**
     * Les mêmes obstacles, réduits à leurs phrases.
     *
     * `CompleteConsultationAction` les assemble en un seul message d'erreur :
     * il n'a que faire des étapes, et les extraire chez lui dupliquerait la
     * connaissance de cette forme.
     *
     * @return array<int, string>
     */
    public function closureBlockerMessages(Consultation $consultation): array
    {
        return array_column($this->blockersForClosure($consultation), 'message');
    }

    /** The conduite à tenir that currently stands, cancelled ones excluded. */
    public function activeOrientation(Consultation $consultation): ?ConsultationOrientation
    {
        return $consultation->relationLoaded('activeOrientation')
            ? $consultation->activeOrientation
            : $consultation->activeOrientation()->first();
    }

    /**
     * What the stepper says under "Clôture": where this patient is heading,
     * and whether the receiving service has actually been told.
     */
    public function orientationNote(Consultation $consultation): ?string
    {
        $orientation = $this->activeOrientation($consultation);

        if (! $orientation) {
            return null;
        }

        return $orientation->isSubmitted()
            ? $orientation->type->label()
            : $orientation->type->label().' · à transmettre';
    }

    private function hasSubmittedOrientation(Consultation $consultation): bool
    {
        return $this->activeOrientation($consultation)?->isSubmitted() === true;
    }

    private function isParaclinicalOnly(Consultation $consultation): bool
    {
        $episode = $consultation->relationLoaded('episode')
            ? $consultation->episode
            : $consultation->episode()->with('serviceRequests')->first();

        if (! $episode) {
            return false;
        }

        $requests = $episode->relationLoaded('serviceRequests')
            ? $episode->serviceRequests
            : $episode->serviceRequests()->get();

        if ($requests->isEmpty()) {
            return false;
        }

        return $requests->every(fn ($request): bool => $request->routing_mode === ReceptionRoutingMode::MedicineDirect
            && in_array($request->module, self::PARACLINICAL_MODULES, true));
    }

    /**
     * Whether an examination was really carried out.
     *
     * Since the examination became structured, requiring the free notes to be
     * filled would be the wrong test: a doctor who recorded the general
     * condition and went through the systems has documented the examination
     * without necessarily writing prose. Any one of the three counts, and an
     * entirely blank record still counts as nothing — a grid left at
     * NOT_EXAMINED everywhere stores no finding row at all.
     */
    private function hasClinicalExamination(Consultation $consultation): bool
    {
        if (filled($consultation->clinical_exam)) {
            return true;
        }

        $examination = $consultation->relationLoaded('clinicalExamination')
            ? $consultation->clinicalExamination
            : $consultation->clinicalExamination()->first();

        if (! $examination) {
            return false;
        }

        return $examination->general_condition !== null
            || $examination->consciousness_status !== null
            || $examination->hasExaminedSystem();
    }

    /**
     * A withdrawn request is not a pending one: once cancelled it no longer
     * justifies the paraclinical step, and the doctor may legitimately
     * declare that no complementary exam is needed after all.
     */
    private function hasParaclinicalRequest(Consultation $consultation): bool
    {
        return $consultation->labRequests()->whereNull('cancelled_at')->exists()
            || $consultation->imagingRequests()->whereNull('cancelled_at')->exists();
    }

    /**
     * What the stepper says under "Paraclinique": how many exams are pending,
     * or that none was needed. "Non nécessaire" is a decision the doctor took
     * — never the same thing as "not started", "absent" or "normal".
     */
    /**
     * What the stepper says under "Diagnostic". A doctor who answered "pas
     * maintenant" is waiting for something — usually results — and the
     * stepper says so rather than showing an unexplained pending step.
     */
    public function diagnosisNote(Consultation $consultation): ?string
    {
        if ($this->hasActiveDiagnosis($consultation)) {
            return null;
        }

        return $this->diagnosisDeferred($consultation) ? 'Diagnostic différé' : null;
    }

    /**
     * The doctor said, explicitly, that they are not concluding yet.
     *
     * Distinct from "no diagnosis": a blank means nobody decided anything,
     * a deferral means someone decided to wait. `null` — never answered —
     * is therefore not a deferral either.
     */
    private function diagnosisDeferred(Consultation $consultation): bool
    {
        $examination = $consultation->relationLoaded('clinicalExamination')
            ? $consultation->clinicalExamination
            : $consultation->clinicalExamination()->first();

        return $examination?->diagnosis_ready === false;
    }

    public function paraclinicalNote(Consultation $consultation): ?string
    {
        $examination = $consultation->relationLoaded('clinicalExamination')
            ? $consultation->clinicalExamination
            : $consultation->clinicalExamination()->first();

        if ($examination?->complementary_exams_required === false) {
            return 'Non nécessaire';
        }

        $count = $consultation->labRequests()->whereNull('cancelled_at')->count()
            + $consultation->imagingRequests()->whereNull('cancelled_at')->count();

        if ($count === 0) {
            return null;
        }

        return $count > 1 ? "{$count} examens demandés" : '1 examen demandé';
    }

    /** Public counterpart: the examination screen needs to check it too. */
    public function hasActiveDiagnosisFor(Consultation $consultation): bool
    {
        return $this->hasActiveDiagnosis($consultation);
    }

    private function hasActiveDiagnosis(Consultation $consultation): bool
    {
        return $consultation->diagnoses()->whereDoesntHave('cancellation')->exists();
    }
}
