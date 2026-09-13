<?php

namespace App\Actions\Medicine;

use App\Actions\Patient\RecordPatientAllergyAction;
use App\Actions\Patient\RecordPatientAntecedentAction;
use App\Actions\Patient\RecordPatientTreatmentAction;
use App\Enums\CatalogModule;
use App\Enums\ConsultationStep as StepKey;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\PatientAntecedentType;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\Patient;
use App\Models\User;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveConsultationAction
{
    public function __construct(
        private readonly ClinicalRichTextSanitizer $richText,
        private readonly ResolveConsultationStepAction $steps,
        private readonly SaveClinicalExaminationAction $clinicalExamination,
        private readonly RecordPatientAllergyAction $recordAllergy,
        private readonly RecordPatientAntecedentAction $recordAntecedent,
        private readonly RecordPatientTreatmentAction $recordTreatment,
    ) {}

    /**
     * Persist only the history reported during the interview. Keeping this
     * boundary separate prevents a stale browser tab from erasing a clinical
     * examination that was saved later in the workflow.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveInterview(EpisodeOrientation $orientation, array $data, User $actor): Consultation
    {
        return DB::transaction(function () use ($orientation, $data, $actor): Consultation {
            $consultation = $this->lockEditableConsultation($orientation);

            $consultation->update([
                'chief_complaint' => $this->nullableText($data['chief_complaint'] ?? null),
                // `reason` is retained as the single rich-text home of the
                // current illness history; no duplicate narrative column.
                'reason' => $this->richText->sanitize((string) ($data['reason'] ?? '')),
                'symptom_onset' => $this->nullableText($data['symptom_onset'] ?? null),
                'evolution' => ($data['evolution'] ?? null) ?: null,
                'additional_notes' => $this->nullableText($data['additional_notes'] ?? null),
                'known_treatment_change' => ($data['known_treatment_change'] ?? null) ?: null,
                'known_treatment_change_notes' => ($data['known_treatment_change'] ?? null) === 'YES'
                    ? $this->nullableText($data['known_treatment_change_notes'] ?? null)
                    : null,
                'interviewed_by' => $actor->getKey(),
                'interviewed_at' => now(),
            ]);
            if (array_key_exists('current_treatments', $data)) {
                $this->syncCurrentTreatments($consultation, $data['current_treatments'] ?? [], $actor);
            }
            $this->syncReportedInformation($consultation, $data, $actor);
            $this->resolveStep($consultation, StepKey::Interview, $data, $actor);

            return $consultation->fresh(['currentTreatments', 'steps']);
        });
    }

    /**
     * Persist only the physician's findings, never the interview.
     *
     * The examination is now hybrid: a structured record (general condition,
     * consciousness, one status per body system) plus optional free notes.
     * Both are written in one transaction with the step status, so an
     * examination is never stored against a step that stayed untouched.
     *
     * It carries no vital sign: those are recorded once by Soins and read
     * back read-only (ADR-054), never re-entered here.
     *
     * @param  array{clinical_exam?: ?string, general_condition?: ?string, consciousness_status?: ?string, consciousness_details?: ?string, general_observation?: ?string, systems?: array<int, array{system_code: string, status: string, findings?: ?string}>, complete?: bool}  $data
     */
    public function saveClinicalExam(
        EpisodeOrientation $orientation,
        array $data,
        User $actor,
    ): Consultation {
        return DB::transaction(function () use ($orientation, $data, $actor): Consultation {
            $consultation = $this->lockEditableConsultation($orientation);

            // Omitting the notes never erases them: a submission that does
            // not carry the field leaves what was already written intact.
            if (array_key_exists('clinical_exam', $data)) {
                $consultation->update([
                    'clinical_exam' => $this->richText->sanitize((string) ($data['clinical_exam'] ?? '')),
                ]);
            }

            $this->clinicalExamination->execute($consultation, $data, $actor);
            $this->resolveStep($consultation, StepKey::ClinicalExam, $data, $actor);

            return $consultation->fresh(['steps', 'clinicalExamination.findings']);
        });
    }

    /**
     * "Enregistrer" leaves the step IN_PROGRESS; "Enregistrer et continuer"
     * validates it. Saving content is never enough on its own to call a
     * step done — that stays the doctor's explicit act.
     */
    private function resolveStep(Consultation $consultation, StepKey $step, array $data, User $actor): void
    {
        if (($data['complete'] ?? true) === false) {
            $this->steps->markInProgress($consultation, $step);

            return;
        }

        $this->steps->complete($consultation, $step, $actor);
    }

    private function lockEditableConsultation(EpisodeOrientation $orientation): Consultation
    {
        $locked = EpisodeOrientation::query()
            ->with(['consultation', 'episode'])
            ->lockForUpdate()
            ->findOrFail($orientation->getKey());

        if ($locked->destination_module !== CatalogModule::Medicine
            || $locked->status !== EpisodeOrientationStatus::InProgress
            || ! $locked->consultation) {
            throw ValidationException::withMessages([
                'consultation' => 'Cette consultation Médecine n’est pas modifiable.',
            ]);
        }

        // The consultation's own status is now the gate. The discharge check
        // below is kept for the episodes closed before that status existed,
        // whose consultation rows the backfill could not always reach.
        if (! $locked->consultation->isEditable()) {
            throw ValidationException::withMessages([
                'consultation' => 'Cette consultation est clôturée : elle est désormais en lecture seule.',
            ]);
        }

        if ($locked->episode->medicalDischarge()->exists()) {
            throw ValidationException::withMessages([
                'consultation' => 'La sortie médicale est déjà prononcée ; cette consultation est désormais en lecture seule.',
            ]);
        }

        return $locked->consultation;
    }

    /**
     * "Traitements actuels" is a corrigible statement of what the patient
     * says they take, not an append-only clinical act: the doctor rewrites
     * the list as the interview clarifies it. Replaced wholesale, and the
     * consultation's own audit trail records the change.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncCurrentTreatments(Consultation $consultation, array $lines, User $actor): void
    {
        $consultation->currentTreatments()->delete();

        foreach (array_values($lines) as $position => $line) {
            $name = trim((string) ($line['medication_name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $consultation->currentTreatments()->create([
                'medication_name' => $name,
                'dosage' => filled($line['dosage'] ?? null) ? trim((string) $line['dosage']) : null,
                'frequency' => filled($line['frequency'] ?? null) ? trim((string) $line['frequency']) : null,
                'duration' => filled($line['duration'] ?? null) ? trim((string) $line['duration']) : null,
                'source' => 'PATIENT_REPORTED',
                'notes' => filled($line['notes'] ?? null) ? trim((string) $line['notes']) : null,
                'position' => $position,
                'recorded_by' => $actor->getKey(),
            ]);
        }
    }

    /**
     * Keep the encounter snapshot regardless of whether the doctor also
     * promotes the item to the permanent record. Promotion is explicit,
     * permission-gated and idempotent by normalized content.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncReportedInformation(Consultation $consultation, array $data, User $actor): void
    {
        $patient = $consultation->episode()->firstOrFail()->patient()->firstOrFail();
        $patient->loadMissing(['allergies', 'antecedents', 'treatments']);

        // Only the blocks the submission actually carries are rewritten.
        // Omitting one never erases what was already reported (ADR-074): a
        // save that does not carry the block leaves it intact, exactly as
        // for the declared treatments below.
        $groups = [];

        if (array_key_exists('reported_allergies', $data)) {
            $groups['reported_allergies'] = $this->normalizeReportedAllergies($data['reported_allergies'] ?? []);
        }

        if (array_key_exists('reported_antecedents', $data)) {
            $groups['reported_antecedents'] = $this->normalizeReportedAntecedents($data['reported_antecedents'] ?? []);
        }

        if (array_key_exists('reported_habitual_treatments', $data)) {
            $groups['reported_habitual_treatments'] = $this->normalizeReportedTreatments($data['reported_habitual_treatments'] ?? []);
        }

        if ($groups === []) {
            return;
        }

        $wantsPromotion = collect($groups)->flatten(1)
            ->contains(fn (array $item): bool => $item['promote_to_patient_record']);

        if ($wantsPromotion && ! $actor->can('patients.medical_history.manage')) {
            throw ValidationException::withMessages([
                'reported_information' => 'Vous pouvez conserver cette information dans la consultation, mais votre compte ne peut pas modifier le dossier patient permanent.',
            ]);
        }

        foreach ($groups['reported_allergies'] ?? [] as $item) {
            if (! $item['promote_to_patient_record'] || $this->patientHasAllergy($patient, $item['substance'])) {
                continue;
            }
            $this->recordAllergy->execute($patient, $item['substance'], $item['reaction'], actor: $actor);
        }

        foreach ($groups['reported_antecedents'] ?? [] as $item) {
            $type = PatientAntecedentType::from($item['type']);
            if (! $item['promote_to_patient_record'] || $this->patientHasAntecedent($patient, $item['description'], $type)) {
                continue;
            }
            $this->recordAntecedent->execute($patient, $item['description'], $type);
        }

        foreach ($groups['reported_habitual_treatments'] ?? [] as $item) {
            if (! $item['promote_to_patient_record'] || $this->patientHasTreatment($patient, $item['medication_name'])) {
                continue;
            }
            $this->recordTreatment->execute($patient, $item, $actor);
        }

        $consultation->update($groups);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function normalizeReportedAllergies(array $items): array
    {
        return collect($items)->map(fn (array $item): array => [
            'substance' => trim((string) ($item['substance'] ?? '')),
            'reaction' => $this->nullableText($item['reaction'] ?? null),
            'promote_to_patient_record' => (bool) ($item['promote_to_patient_record'] ?? false),
        ])->filter(fn (array $item): bool => $item['substance'] !== '')->values()->all();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function normalizeReportedAntecedents(array $items): array
    {
        return collect($items)->map(fn (array $item): array => [
            'description' => trim((string) ($item['description'] ?? '')),
            'type' => (string) ($item['type'] ?? PatientAntecedentType::Personal->value),
            'promote_to_patient_record' => (bool) ($item['promote_to_patient_record'] ?? false),
        ])->filter(fn (array $item): bool => $item['description'] !== '')->values()->all();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function normalizeReportedTreatments(array $items): array
    {
        return collect($items)->map(fn (array $item): array => [
            'medication_name' => trim((string) ($item['medication_name'] ?? '')),
            'dosage' => $this->nullableText($item['dosage'] ?? null),
            'frequency' => $this->nullableText($item['frequency'] ?? null),
            'duration' => $this->nullableText($item['duration'] ?? null),
            'notes' => $this->nullableText($item['notes'] ?? null),
            'promote_to_patient_record' => (bool) ($item['promote_to_patient_record'] ?? false),
        ])->filter(fn (array $item): bool => $item['medication_name'] !== '')->values()->all();
    }

    private function patientHasAllergy(Patient $patient, string $substance): bool
    {
        return $patient->allergies->contains(fn ($allergy): bool => $this->sameText($allergy->substance, $substance));
    }

    private function patientHasAntecedent(Patient $patient, string $description, PatientAntecedentType $type): bool
    {
        return $patient->antecedents->contains(fn ($antecedent): bool => $antecedent->type === $type
            && $this->sameText($antecedent->description, $description));
    }

    private function patientHasTreatment(Patient $patient, string $name): bool
    {
        return $patient->treatments->contains(fn ($treatment): bool => $treatment->active
            && $this->sameText($treatment->medication_name, $name));
    }

    private function sameText(?string $left, ?string $right): bool
    {
        return mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
