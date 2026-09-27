<?php

namespace App\Actions\Medicine;

use App\Enums\CatalogModule;
use App\Enums\ConsultationStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Models\CareOrder;
use App\Models\Consultation;
use App\Models\ConsultationDraft;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remettre en file un patient pris en charge par erreur en Médecine (ADR-127).
 *
 * Un clic au mauvais endroit — un patient dont ce n'est pas encore le tour — ne doit
 * pas obliger le médecin à ouvrir sa consultation. Le patient retrouve **sa place** :
 * l'ordre de la file est celui de `oriented_at`, que la prise en charge n'a jamais
 * touché.
 *
 * Prendre un patient ouvre une consultation. Elle n'est défaite que si elle est
 * **restée vierge** : motif d'office inchangé, aucun interrogatoire, aucun examen, aucun
 * diagnostic, aucune ordonnance, aucune demande, aucune étape validée, aucune orientation.
 * Dès qu'une seule ligne clinique existe, le médecin a réellement commencé et le patient
 * ne se « rend » plus : la suite passe par la clôture et la réouverture tracées
 * (ADR-076, ADR-096) — jamais par une suppression.
 *
 * La consultation vierge n'est pas une donnée médicale, c'est le reste de l'erreur : elle
 * est supprimée (`consultation.orientation_id` est unique, elle doit disparaître pour que
 * la prochaine prise en charge en ouvre une propre) et cette suppression est tracée.
 */
class ReleaseMedicineOrientationAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @throws ValidationException */
    public function execute(EpisodeOrientation $orientation, User $actor): EpisodeOrientation
    {
        return DB::transaction(function () use ($orientation, $actor): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with('episode')
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Medicine) {
                throw ValidationException::withMessages(['orientation' => 'Cette orientation ne concerne pas le service Médecine.']);
            }

            if ($locked->status !== EpisodeOrientationStatus::InProgress) {
                throw ValidationException::withMessages(['orientation' => 'Ce patient n’est pas en cours de prise en charge en Médecine.']);
            }

            if ($locked->accepted_by !== null && $locked->accepted_by !== $actor->getKey()) {
                throw ValidationException::withMessages(['orientation' => 'Seule la personne qui a pris ce patient en charge peut le remettre en file.']);
            }

            if ($locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['orientation' => 'Ce passage est clos : le patient ne peut plus être remis en file.']);
            }

            $consultation = Consultation::query()->where('episode_orientation_id', $locked->getKey())->first();

            if ($consultation !== null && ! $this->isUntouched($consultation, $locked)) {
                throw ValidationException::withMessages(['orientation' => 'La consultation de ce patient est déjà commencée : il ne peut plus être remis en file.']);
            }

            $before = [
                'status' => $locked->status->value,
                'accepted_by' => $locked->accepted_by,
                'accepted_at' => $locked->accepted_at?->toIso8601String(),
                'consultation' => $consultation?->uuid,
            ];

            ConsultationDraft::query()->where('episode_orientation_id', $locked->getKey())->delete();

            if ($consultation !== null) {
                $consultation->forceDelete();
            }

            $locked->status = EpisodeOrientationStatus::Pending;
            $locked->accepted_by = null;
            $locked->accepted_at = null;
            $locked->save();

            $this->undoEpisodeStatuses($locked);

            $this->auditor->record(
                'medicine.orientation.release',
                entity: $locked,
                oldValues: $before,
                newValues: ['status' => $locked->status->value, 'accepted_by' => null, 'accepted_at' => null, 'consultation' => null],
            );

            return $locked->fresh(['episode.patient']);
        });
    }

    /** Vrai tant que rien de clinique n'a été enregistré : la consultation est exactement ce que la prise en charge a créée. */
    private function isUntouched(Consultation $consultation, EpisodeOrientation $orientation): bool
    {
        if ($consultation->status !== ConsultationStatus::InProgress) {
            return false;
        }

        $written = [
            $consultation->chief_complaint, $consultation->symptom_onset, $consultation->additional_notes,
            $consultation->known_treatment_change_notes, $consultation->clinical_exam, $consultation->decision_notes,
        ];

        foreach ($written as $text) {
            if (filled(is_string($text) ? trim(strip_tags($text)) : $text)) {
                return false;
            }
        }

        if (
            $consultation->evolution !== null
            || $consultation->decision !== null
            || $consultation->interviewed_at !== null
            || $consultation->known_treatment_change !== null
            || filled($consultation->reported_allergies)
            || filled($consultation->reported_antecedents)
            || filled($consultation->reported_habitual_treatments)
        ) {
            return false;
        }

        // Le motif inscrit d'office : s'il a été retouché, le médecin a écrit dedans.
        if (trim((string) $consultation->reason) !== AcceptMedicineOrientationAction::initialReasonFor($orientation)) {
            return false;
        }

        return ! $consultation->currentTreatments()->exists()
            && ! $consultation->clinicalExamination()->exists()
            && ! $consultation->steps()->exists()
            && ! $consultation->diagnoses()->exists()
            && ! $consultation->labRequests()->exists()
            && ! $consultation->imagingRequests()->exists()
            && ! $consultation->prescriptions()->exists()
            && ! $consultation->medicalDischarge()->exists()
            && ! $consultation->orientations()->exists()
            && ! CareOrder::query()->where('consultation_id', $consultation->getKey())->exists();
    }

    /**
     * La prise en charge avait mis le passage « en cours de soins » : si plus personne
     * n'y travaille, il redevient simplement orienté.
     */
    private function undoEpisodeStatuses(EpisodeOrientation $orientation): void
    {
        $episode = $orientation->episode;
        $othersWorking = EpisodeOrientation::query()
            ->where('episode_id', $episode->getKey())
            ->whereKeyNot($orientation->getKey())
            ->where('status', EpisodeOrientationStatus::InProgress->value)
            ->exists();

        if ($othersWorking) {
            return;
        }

        if ($episode->administrative_status === EpisodeAdministrativeStatus::InCare) {
            $episode->administrative_status = EpisodeAdministrativeStatus::Oriented;
        }

        // Le statut médical « en cours de soins » vient de cette prise en charge ; une
        // consultation antérieure du même passage le justifie encore.
        if (! Consultation::query()->where('episode_id', $episode->getKey())->exists()) {
            $episode->medical_status = null;
        }

        $episode->save();
    }
}
