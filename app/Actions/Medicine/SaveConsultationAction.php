<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Medicine\ClinicalRichTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveConsultationAction
{
    public function __construct(private readonly ClinicalRichTextSanitizer $richText) {}

    /**
     * @param  array{reason: string, clinical_exam?: ?string, decision?: ?string, decision_notes?: ?string, current_treatments?: array<int, array{medication_name: string, dosage?: ?string, notes?: ?string}>}  $data
     */
    public function execute(EpisodeOrientation $orientation, array $data, User $actor): Consultation
    {
        return DB::transaction(function () use ($orientation, $data, $actor): Consultation {
            $locked = EpisodeOrientation::query()
                ->with('consultation')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Medicine
                || $locked->status !== EpisodeOrientationStatus::InProgress
                || ! $locked->consultation) {
                throw ValidationException::withMessages([
                    'consultation' => 'Cette consultation Médecine n’est pas modifiable.',
                ]);
            }

            if ($locked->episode->medicalDischarge()->exists()) {
                throw ValidationException::withMessages([
                    'consultation' => 'La sortie médicale est déjà prononcée ; cette consultation est désormais en lecture seule.',
                ]);
            }

            $locked->consultation->update([
                'reason' => $this->richText->sanitize($data['reason']),
                'clinical_exam' => isset($data['clinical_exam'])
                    ? $this->richText->sanitize($data['clinical_exam'])
                    : null,
                'decision' => $data['decision'] ?? null,
                'decision_notes' => $data['decision_notes'] ?? null,
            ]);

            if (array_key_exists('current_treatments', $data)) {
                $this->syncCurrentTreatments($locked->consultation, $data['current_treatments'] ?? [], $actor);
            }

            return $locked->consultation->fresh('currentTreatments');
        });
    }

    /**
     * "Traitements actuels" is a corrigible statement of what the patient
     * says they take, not an append-only clinical act: the doctor rewrites
     * the list as the interview clarifies it. Replaced wholesale, and the
     * consultation's own audit trail records the change.
     *
     * @param  array<int, array{medication_name: string, dosage?: ?string, notes?: ?string}>  $lines
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
                'notes' => filled($line['notes'] ?? null) ? trim((string) $line['notes']) : null,
                'position' => $position,
                'recorded_by' => $actor->getKey(),
            ]);
        }
    }
}
