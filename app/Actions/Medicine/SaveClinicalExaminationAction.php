<?php

namespace App\Actions\Medicine;

use App\Enums\ClinicalExamSystem;
use App\Enums\ClinicalSystemStatus;
use App\Models\ClinicalExamination;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Persists the structured clinical examination of one consultation.
 *
 * Called inside the transaction already opened by SaveConsultationAction, so
 * the free notes, the structured examination and the step status either all
 * land or none do — never an examination recorded against a step that stayed
 * untouched.
 */
class SaveClinicalExaminationAction
{
    /**
     * @param  array{
     *     general_condition?: ?string,
     *     consciousness_status?: ?string,
     *     consciousness_details?: ?string,
     *     general_observation?: ?string,
     *     systems?: array<int, array{system_code: string, status: string, findings?: ?string}>
     * }  $data
     */
    public function execute(Consultation $consultation, array $data, User $actor): ClinicalExamination
    {
        // Read before writing: omitting the answer must leave the previous
        // one intact rather than silently clearing it (ADR-074).
        $examinationBefore = $consultation->clinicalExamination()->first();

        $examination = ClinicalExamination::query()->updateOrCreate(
            ['consultation_id' => $consultation->getKey()],
            [
                'general_condition' => $data['general_condition'] ?? null,
                'consciousness_status' => $data['consciousness_status'] ?? null,
                // A precision only means something for "Autre"; keeping one
                // typed before switching back would leave an orphan comment
                // attached to a status it no longer describes.
                'consciousness_details' => ($data['consciousness_status'] ?? null) === 'OTHER'
                    ? $this->trimmed($data['consciousness_details'] ?? null)
                    : null,
                'general_observation' => $this->trimmed($data['general_observation'] ?? null),
                // Never defaulted: an unanswered question stays null, and the
                // screen renders neither "Oui" nor "Non" as pre-selected.
                'complementary_exams_required' => array_key_exists('complementary_exams_required', $data)
                    ? $this->nullableBool($data['complementary_exams_required'])
                    : ($examinationBefore?->complementary_exams_required),
                'diagnosis_ready' => array_key_exists('diagnosis_ready', $data)
                    ? $this->nullableBool($data['diagnosis_ready'])
                    : ($examinationBefore?->diagnosis_ready),
                'examined_by' => $actor->getKey(),
                'examined_at' => now(),
            ],
        );

        if (array_key_exists('systems', $data)) {
            $this->syncSystems($examination, $data['systems'] ?? []);
        }

        return $examination->fresh('findings');
    }

    /**
     * The grid is a corrigible statement of what the doctor examined, not an
     * append-only act: it is rewritten as the examination proceeds. Systems
     * the doctor left untouched keep no row at all — `systems()` reports them
     * as NOT_EXAMINED, so an absent row can never be read as normal.
     *
     * @param  array<int, array{system_code: string, status: string, findings?: ?string}>  $systems
     */
    private function syncSystems(ClinicalExamination $examination, array $systems): void
    {
        $keep = [];

        foreach ($systems as $entry) {
            $system = ClinicalExamSystem::tryFrom((string) ($entry['system_code'] ?? ''));
            $status = ClinicalSystemStatus::tryFrom((string) ($entry['status'] ?? ''));

            if (! $system || ! $status) {
                continue;
            }

            // NOT_EXAMINED is the absence of a statement: storing it would
            // make "I did not look" indistinguishable from a row the doctor
            // actually filled, and would grow one row per system per patient
            // for nothing.
            if ($status === ClinicalSystemStatus::NotExamined) {
                continue;
            }

            $findings = $this->trimmed($entry['findings'] ?? null);

            // Restated here because an Action is reachable from anywhere, not
            // only through the FormRequest: an anomaly without its findings
            // tells a later reader that something is wrong but not what.
            if ($status->requiresFindings() && $findings === null) {
                throw ValidationException::withMessages([
                    'systems' => sprintf(
                        'Veuillez renseigner les constatations de l’anomalie (%s).',
                        $system->label(),
                    ),
                ]);
            }

            $examination->findings()->updateOrCreate(
                ['system_code' => $system->value],
                [
                    'status' => $status,
                    // Findings belong to an anomaly. Keeping text typed before
                    // the doctor settled on "Normal" would contradict the
                    // status stored beside it.
                    'findings' => $status === ClinicalSystemStatus::Normal ? null : $findings,
                    'sort_order' => $system->sortOrder(),
                ],
            );
            $keep[] = $system->value;
        }

        $examination->findings()
            ->whereNotIn('system_code', $keep)
            ->delete();
    }

    /** Tri-state: null stays null, so "not decided" survives a save. */
    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
