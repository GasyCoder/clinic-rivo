<?php

namespace App\Actions\Medicine;

use App\Actions\Hospitalization\CancelHospitalStayAction;
use App\Enums\ClinicalPriority;
use App\Enums\ConsultationOrientationStatus;
use App\Enums\ConsultationOrientationType;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\MedicalDischargeType;
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
    public function __construct(private readonly CancelHospitalStayAction $cancelStay) {}

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

                return $this->attachPronouncedDischarge($locked, $active->fresh());
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

            return $this->attachPronouncedDischarge($locked, $orientation);
        });
    }

    /**
     * The request really went out: the row now points at the business object
     * that carries it, and the receiving service has something to act on.
     *
     * @param  array<string, int|null>  $links
     */
    /**
     * Une sortie déjà prononcée **est** la demande transmise.
     *
     * Le cul-de-sac constaté : la clôture exigeait « complétez sa demande »
     * sur une orientation `SELECTED`, alors que la sortie médicale figurait
     * juste en dessous, prononcée et datée. Seul
     * `RecordMedicalDischargeAction` fait passer l'orientation à `SUBMITTED`
     * — et il refuse d'agir quand une sortie existe déjà. Le médecin ne
     * pouvait donc ni transmettre ni clôturer.
     *
     * On ne fabrique rien : on rattache l'orientation au fait déjà
     * enregistré. Le type doit correspondre — un transfert répond à
     * `Referral`, toute autre sortie à `Discharge` — sinon choisir
     * « Référence » sur une sortie normale se transmettrait tout seul.
     */
    private function attachPronouncedDischarge(
        Consultation $consultation,
        ConsultationOrientation $orientation,
    ): ConsultationOrientation {
        if ($orientation->status === ConsultationOrientationStatus::Submitted) {
            return $orientation;
        }

        if (! in_array($orientation->type, [
            ConsultationOrientationType::Discharge,
            ConsultationOrientationType::Referral,
        ], true)) {
            return $orientation;
        }

        $discharge = $consultation->medicalDischarge()->first();

        if (! $discharge) {
            return $orientation;
        }

        $expected = $discharge->type === MedicalDischargeType::Transfer
            ? ConsultationOrientationType::Referral
            : ConsultationOrientationType::Discharge;

        if ($orientation->type !== $expected) {
            return $orientation;
        }

        $orientation->update([
            'medical_discharge_id' => $discharge->getKey(),
            'status' => ConsultationOrientationStatus::Submitted,
            'submitted_at' => $discharge->discharged_at ?? now(),
        ]);

        return $orientation->fresh();
    }

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

        // ADR-113 — une hospitalisation est admise dès la demande : son
        // orientation n'est donc jamais « en attente ». Tant que la fiche de
        // régime n'a pas commencé, le retrait annule le séjour ; ensuite il
        // est refusé et c'est la sortie médicale qui termine le séjour.
        $stay = $orientation->hospitalizationRequest?->hospitalStay;

        if ($stay?->isActive()) {
            $this->cancelStay->execute($stay, $reason, $actor);
            $orientation->load('episodeOrientation');
        }

        $episodeOrientation = $orientation->episodeOrientation;

        if ($episodeOrientation
            && ! in_array($episodeOrientation->status, [EpisodeOrientationStatus::Pending, EpisodeOrientationStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'orientation' => sprintf(
                    'Le service %s a déjà pris en charge cette demande : elle ne peut plus être retirée ici.',
                    $episodeOrientation->destination_module->label(),
                ),
            ]);
        }

        $surgicalRequest?->update(['status' => SurgicalRequestStatus::Cancelled]);
        if ($episodeOrientation?->status === EpisodeOrientationStatus::Pending) {
            $episodeOrientation->cancel($actor);
        }

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

    /**
     * ADR-114 — une demande déjà transmise ne repart pas une seconde fois.
     *
     * Retransmettre créait un second enregistrement (un transfert en double
     * dans la liste, une seconde demande au bloc) au lieu de corriger le
     * premier. Une demande transmise se complète dans son module ; pour
     * changer d'avis, on change d'orientation, ce qui annule proprement.
     */
    public function ensureNotAlreadySubmitted(Consultation $consultation, ConsultationOrientationType $type): void
    {
        $active = $this->activeOrientation($consultation);

        if ($active?->type === $type && $active->status === ConsultationOrientationStatus::Submitted) {
            throw ValidationException::withMessages([
                'orientation' => 'Cette demande a déjà été transmise. Complétez-la dans son espace, ou changez d’orientation.',
            ]);
        }
    }

    private function activeOrientation(Consultation $consultation): ?ConsultationOrientation
    {
        return $consultation->orientations()
            ->whereNotNull('active_key')
            ->with(['surgicalRequest', 'episodeOrientation', 'hospitalizationRequest.hospitalStay', 'medicalReferral'])
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
