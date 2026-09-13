<?php

namespace App\Actions\Medicine;

use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationStatus;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalRequestStatus;
use App\Enums\SurgicalRequestStatus;
use App\Models\Consultation;
use App\Models\ConsultationOrientation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the conduite à tenir, and everything that follows from changing
 * it (ADR-084).
 *
 * Selecting is not submitting. A doctor who has chosen Chirurgie but not yet
 * filled the request has given the block nothing to act on, so the two are
 * different statuses and closure checks the second one.
 *
 * Changing course is legitimate right up to closure, but a change never
 * erases what was already sent. It cancels — and only what the destination
 * has not yet taken up. Once Chirurgie has scheduled, once a service has
 * accepted the patient, once a discharge has been pronounced, the change is
 * refused with an explicit message instead of silently unpicking another
 * module's work (ADR-010).
 */
class RecordConsultationOrientationAction
{
    /**
     * The doctor states where this patient is heading. Re-selecting the same
     * destination only refreshes the priority — it never cancels a request
     * that has already gone out.
     */
    public function select(
        Consultation $consultation,
        ConsultationOrientationType $type,
        ?ClinicalPriority $priority,
        User $actor,
    ): ConsultationOrientation {
        return DB::transaction(function () use ($consultation, $type, $priority, $actor): ConsultationOrientation {
            $locked = $this->lockEditable($consultation);
            $active = $this->activeOrientation($locked);

            if ($active && $active->type === $type) {
                if ($priority !== null && $active->priority !== $priority) {
                    $active->update(['priority' => $priority]);
                }

                return $active->fresh();
            }

            if ($active) {
                $this->withdraw($active, 'Orientation remplacée par : '.$type->label(), $actor);
            }

            $orientation = $locked->orientations()->create([
                'type' => $type,
                'status' => ConsultationOrientationStatus::Selected,
                'priority' => $priority,
                'selected_by' => $actor->getKey(),
                'selected_at' => now(),
                'active_key' => ConsultationOrientation::activeKeyFor($locked),
            ]);

            // `consultations.decision` keeps carrying the same intent it
            // always did, so the passage detail page and the episode API need
            // to learn nothing new (§32).
            $locked->update(['decision' => $type->legacyDecision()]);

            return $orientation;
        });
    }

    /**
     * The request really went out: the row now points at the business object
     * that carries it, and the receiving service has something to act on.
     *
     * @param  array<string, int|null>  $links
     */
    public function submit(
        Consultation $consultation,
        ConsultationOrientationType $type,
        array $links,
        ?ClinicalPriority $priority,
        User $actor,
    ): ConsultationOrientation {
        $orientation = $this->select($consultation, $type, $priority, $actor);

        $orientation->update(array_merge($links, [
            'status' => ConsultationOrientationStatus::Submitted,
            'submitted_at' => now(),
        ]));

        return $orientation->fresh();
    }

    /**
     * "Poursuivre l'évaluation" after having chosen: the doctor is no longer
     * committing to a destination. Same withdrawal rules as a change — an
     * already-transmitted request is not undone by an interface toggle.
     */
    public function clear(Consultation $consultation, User $actor): void
    {
        DB::transaction(function () use ($consultation, $actor): void {
            $locked = $this->lockEditable($consultation);
            $active = $this->activeOrientation($locked);

            if (! $active) {
                return;
            }

            $this->withdraw($active, 'Orientation retirée : poursuite de l’évaluation.', $actor);
            $locked->update(['decision' => null]);
        });
    }

    /**
     * Cancels an orientation and whatever it had already produced, refusing
     * as soon as the destination has moved.
     */
    private function withdraw(ConsultationOrientation $orientation, string $reason, User $actor): void
    {
        if ($orientation->medical_discharge_id !== null) {
            throw ValidationException::withMessages([
                'orientation' => 'Une sortie médicale a déjà été prononcée : elle ne peut pas être retirée depuis cet écran.',
            ]);
        }

        $surgicalRequest = $orientation->surgicalRequest;

        if ($surgicalRequest && $surgicalRequest->status !== SurgicalRequestStatus::Pending) {
            throw ValidationException::withMessages([
                'orientation' => 'La Chirurgie a déjà pris en charge cette demande : changez d’orientation avec le bloc.',
            ]);
        }

        $episodeOrientation = $orientation->episodeOrientation;

        if ($episodeOrientation && $episodeOrientation->status !== EpisodeOrientationStatus::Pending) {
            throw ValidationException::withMessages([
                'orientation' => sprintf(
                    'Le service %s a déjà pris en charge cette demande : elle ne peut plus être retirée ici.',
                    $episodeOrientation->destination_module->label(),
                ),
            ]);
        }

        $surgicalRequest?->update(['status' => SurgicalRequestStatus::Cancelled]);
        $episodeOrientation?->cancel($actor);

        foreach ([$orientation->hospitalizationRequest, $orientation->medicalReferral] as $request) {
            $request?->update([
                'status' => MedicalRequestStatus::Cancelled,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
        }

        $orientation->update([
            'status' => ConsultationOrientationStatus::Cancelled,
            'cancelled_by' => $actor->getKey(),
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            // Releasing the key is what allows another orientation; the row
            // itself stays, with its author, its date and its request.
            'active_key' => null,
        ]);
    }

    private function activeOrientation(Consultation $consultation): ?ConsultationOrientation
    {
        return $consultation->orientations()
            ->whereNotNull('active_key')
            ->with(['surgicalRequest', 'episodeOrientation', 'hospitalizationRequest', 'medicalReferral'])
            ->lockForUpdate()
            ->first();
    }

    private function lockEditable(Consultation $consultation): Consultation
    {
        $locked = Consultation::query()->lockForUpdate()->findOrFail($consultation->getKey());

        if (! $locked->isEditable()) {
            throw ValidationException::withMessages([
                'orientation' => 'Cette consultation est clôturée : son orientation n’est plus modifiable.',
            ]);
        }

        return $locked;
    }
}
