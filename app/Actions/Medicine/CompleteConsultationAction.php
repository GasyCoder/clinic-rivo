<?php

namespace App\Actions\Medicine;

use App\Enums\ConsultationOrientationType;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationStep;
use App\Enums\ConsultationStepStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
use App\Models\Consultation;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Support\ConsultationWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes the encounter. From here the consultation is read-only through the
 * ordinary save paths (ADR-010): validated medical data is never silently
 * overwritten, and a later correction needs its own traced mechanism.
 *
 * Closure requires every step that is relevant *for this patient* to be
 * resolved. It deliberately requires no laboratory request, no imaging, no
 * prescription and no hospitalisation: none of those concerns every
 * encounter, and demanding them would push doctors to fabricate acts.
 */
class CompleteConsultationAction
{
    public function __construct(
        private readonly ConsultationWorkflow $workflow,
        private readonly RecordConsultationOrientationAction $recordOrientation,
    ) {}

    public function execute(Consultation $consultation, User $actor): Consultation
    {
        return DB::transaction(function () use ($consultation, $actor): Consultation {
            $locked = Consultation::query()
                ->with(['episode.serviceRequests', 'steps'])
                ->lockForUpdate()
                ->findOrFail($consultation->getKey());

            if ($locked->status === ConsultationStatus::Completed) {
                // Idempotent: a double click or a retry must not produce a
                // second closure or move `completed_at`.
                return $locked;
            }

            if (! $locked->isEditable()) {
                throw ValidationException::withMessages([
                    'consultation' => 'Cette consultation n’est plus modifiable.',
                ]);
            }

            // ADR-156 — une sortie déjà prononcée sur le passage EST la
            // conduite à tenir de cette rencontre : la redemander par un clic
            // revenait à faire ressaisir un fait daté et signé, affiché juste
            // au-dessus (ADR-107, même raisonnement qu'`attachPronouncedDischarge`).
            // Elle n'est jamais fabriquée : on rattache ce qui existe, et rien
            // d'autre ne pourrait conclure un passage médicalement sorti.
            $this->attachPronouncedDischarge($locked, $actor);

            $blockers = $this->workflow->closureBlockerMessages($locked->fresh());

            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'consultation' => 'Clôture impossible — '.implode(' ', $blockers),
                ]);
            }

            $locked->update([
                'status' => ConsultationStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
            ]);

            // Closing the consultation is what resolves the Clôture step —
            // asking the doctor to validate it first and then close would be
            // the same act twice.
            $locked->steps()->updateOrCreate(
                ['step' => ConsultationStep::Closure->value],
                [
                    'status' => ConsultationStepStatus::Completed,
                    'completed_at' => now(),
                    'completed_by' => $actor->getKey(),
                    'skip_reason' => null,
                ],
            );

            $this->endMedicalPathway($locked, $actor);

            return $locked->fresh(['steps']);
        });
    }

    /**
     * Rattache la sortie déjà prononcée du passage comme conduite à tenir,
     * quand la consultation n'en porte aucune.
     *
     * Le cas : une sortie prononcée sous l'ancien second formulaire du séjour,
     * ou dans une autre rencontre du même passage. Un passage n'a qu'une
     * sortie médicale — aucune autre destination ne peut conclure celle-ci.
     */
    private function attachPronouncedDischarge(Consultation $consultation, User $actor): void
    {
        if ($this->workflow->activeOrientation($consultation)) {
            return;
        }

        $discharge = $consultation->episode()->first()?->medicalDischarge()->first();

        if (! $discharge) {
            return;
        }

        $this->recordOrientation->select(
            $consultation,
            $discharge->type === MedicalDischargeType::Transfer
                ? ConsultationOrientationType::Referral
                : ConsultationOrientationType::Discharge,
            null,
            $actor,
        );
    }

    /**
     * Closing the consultation is what ends the Médecine orientation, and
     * what carries a pronounced discharge onto the episode (ADR-084).
     *
     * Recording a discharge used to do both immediately, which made the
     * encounter read-only the moment the doctor filled that form — so a
     * discharge could never be followed by a prescription, a printout or a
     * final check. Those two effects now happen once, here, when the doctor
     * says they are done.
     *
     * Asking Soins for an act no longer ends it (ADR-088): the consultation
     * stays open while the patient is at Soins. A consultation recorded
     * before that change may still point at an orientation already closed,
     * so its state is checked rather than assumed.
     */
    private function endMedicalPathway(Consultation $consultation, User $actor): void
    {
        $orientation = EpisodeOrientation::query()
            ->with('episode')
            ->lockForUpdate()
            ->find($consultation->episode_orientation_id);

        if (! $orientation) {
            return;
        }

        if ($orientation->status === EpisodeOrientationStatus::InProgress) {
            $orientation->complete($actor);
        }

        $episode = $orientation->episode;
        $discharge = $consultation->medicalDischarge()->first();

        if ($discharge) {
            $episode->medical_status = $discharge->type->medicalStatus();
        }

        // The clinical pathway is over; what remains is administrative and
        // financial. Never a discharge invented by this transition, and never
        // a regression of a status Réception already moved on (ADR-054).
        if ($episode->administrative_status === EpisodeAdministrativeStatus::InCare
            && ! $episode->orientations()
                ->whereIn('status', [
                    EpisodeOrientationStatus::Pending->value,
                    EpisodeOrientationStatus::InProgress->value,
                ])
                ->exists()) {
            $episode->administrative_status = EpisodeAdministrativeStatus::PendingSettlement;
        }

        $episode->save();
    }
}
