<?php

namespace App\Actions\Care;

use App\Actions\Episode\CreateEpisodeOrientationAction;
use App\Enums\CareCompletionMode;
use App\Enums\CareOrderStatus;
use App\Enums\CatalogModule;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\HospitalStayStatus;
use App\Models\CareOrder;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\CareHandlerGuard;
use App\Support\CareWorkflow;
use App\Support\EpisodeSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CompleteCareAndOrientToMedicineAction
{
    public function __construct(
        private readonly CreateEpisodeOrientationAction $createOrientation,
        private readonly CareWorkflow $careWorkflow,
        private readonly Auditor $auditor,
    ) {}

    /**
     * ADR-166 — la suite des Soins est décidée par l'infirmier, pas imposée
     * par la désignation d'arrivée :
     *
     *   `$destination` null            suivre le parcours prévu (ADR-030)
     *   CareCompletionMode::Medicine   transmettre au médecin, même un patient
     *                                  prévu aux Soins seuls — avec un motif
     *   CareCompletionMode::Finish     terminer aux Soins, même un patient
     *                                  attendu en Médecine — avec un motif
     *
     * L'ordre de soins d'un médecin garde sa propre suite (ADR-055) : c'est
     * le médecin qui décide, l'infirmier ne la change pas.
     */
    public function execute(
        EpisodeOrientation $orientation,
        User $actor,
        ?CareCompletionMode $destination = null,
        ?string $outcomeReason = null,
    ): EpisodeOrientation {
        return DB::transaction(function () use ($orientation, $actor, $destination, $outcomeReason): EpisodeOrientation {
            $locked = EpisodeOrientation::query()
                ->with(['episode.serviceRequests', 'episode.careRecord.procedures'])
                ->lockForUpdate()
                ->findOrFail($orientation->getKey());

            if ($locked->destination_module !== CatalogModule::Care) {
                throw new InvalidArgumentException('Cette orientation ne concerne pas le service Soins.');
            }

            CareHandlerGuard::ensureWorkable($locked, $actor);

            $activeCareOrder = CareOrder::query()
                ->where('care_orientation_id', $locked->getKey())
                ->where('status', CareOrderStatus::Pending)
                ->lockForUpdate()
                ->first();

            if ($activeCareOrder) {
                return $this->completeCareOrderPathway($locked, $activeCareOrder, $actor);
            }

            $planned = $this->careWorkflow->completionMode($locked->episode);
            $medicineInvolved = $this->careWorkflow->medicineAlreadyInvolved($locked->episode, forUpdate: true);
            $toMedicine = $destination === CareCompletionMode::Medicine
                || ($destination === null && $planned === CareCompletionMode::Medicine);
            $procedureCount = $locked->episode->careRecord?->procedures->count() ?? 0;

            // Toute suite qui s'écarte du parcours prévu exige un motif, dans
            // les deux sens (ADR-166, amendement du 2026-09-21) : terminer aux
            // Soins un patient que le médecin attend supprime une consultation
            // prévue ; envoyer au médecin un patient prévu aux Soins seuls
            // ajoute une consultation que personne n'attendait. Un besoin
            // inconnu n'a pas de parcours prévu, donc rien dont s'écarter.
            // Quand Médecine a déjà le patient (urgence), rien n'est supprimé.
            $skipsPlannedMedicine = ! $toMedicine
                && $planned === CareCompletionMode::Medicine
                && ! $medicineInvolved;
            $sendsOffPlan = $toMedicine
                && $planned === CareCompletionMode::Finish
                && ! $medicineInvolved;
            $deviates = $skipsPlannedMedicine || $sendsOffPlan;
            $reason = trim((string) $outcomeReason);

            if ($deviates && $reason === '') {
                throw ValidationException::withMessages([
                    'care_outcome_reason' => $skipsPlannedMedicine
                        ? 'Indiquez pourquoi le patient ne passe pas en Médecine.'
                        : 'Indiquez pourquoi le patient doit voir le médecin alors que seuls des soins étaient prévus.',
                ]);
            }

            if (! $toMedicine && $planned === CareCompletionMode::Finish && $procedureCount === 0) {
                throw ValidationException::withMessages([
                    'procedures' => 'Enregistrez au moins un acte réellement réalisé avant de terminer les soins.',
                ]);
            }

            if (! $toMedicine
                && $planned === CareCompletionMode::Choice
                && $procedureCount === 0
                && blank($locked->episode->careRecord?->no_procedure_reason)) {
                throw ValidationException::withMessages([
                    'no_procedure_reason' => 'Indiquez pourquoi aucun acte n’a été réalisé, ou orientez le patient vers Médecine.',
                ]);
            }

            $locked->complete($actor, $deviates ? $reason : null);

            if ($toMedicine) {
                if (! $medicineInvolved) {
                    $this->createOrientation->execute(
                        $locked->episode,
                        CatalogModule::Care,
                        CatalogModule::Medicine,
                        $actor,
                        match ($planned) {
                            CareCompletionMode::Medicine => 'Orientation vers Médecine selon le parcours planifié.',
                            CareCompletionMode::Choice => 'Orientation explicite après évaluation d’un besoin initialement inconnu.',
                            CareCompletionMode::Finish => 'Orientation vers Médecine décidée aux Soins, hors du parcours prévu — motif : '.$reason,
                        },
                    );
                }
            } elseif ($planned === CareCompletionMode::Finish) {
                $this->settleAdministrativelyIfPathwayComplete($locked->episode);
            } elseif ($skipsPlannedMedicine) {
                // Même règle que l'ADR-054, sous sa forme générale : le passage
                // rejoint « Sorties & règlements » dès qu'aucun service n'a
                // plus le patient.
                EpisodeSettlement::advanceWhenNoServiceLeft($locked->episode->refresh());
            }

            if ($deviates) {
                $this->auditor->record(
                    $skipsPlannedMedicine ? 'care.orientation.finish_at_care' : 'care.orientation.send_to_medicine',
                    entity: $locked,
                    oldValues: ['planned' => $planned->value],
                    newValues: [
                        'outcome' => ($skipsPlannedMedicine ? CareCompletionMode::Finish : CareCompletionMode::Medicine)->value,
                        'reason' => $reason,
                    ],
                );
            }

            return $locked->fresh(['episode.patient']);
        });
    }

    /** Explicit convenience entry point for an initially unknown need. */
    public function executeForUnknownNeed(
        EpisodeOrientation $orientation,
        User $actor,
    ): EpisodeOrientation {
        return $this->execute($orientation, $actor, CareCompletionMode::Medicine);
    }

    /**
     * A CARE_ONLY pathway ends the clinical routing, never the episode
     * itself: only the administrative/financial side is unblocked so
     * Réception/Caisse can proceed (ADR-030 defines no discharge for a
     * Care-only visit). Guarded on the current status so this stays a
     * no-op once already past IN_CARE, and on the absence of an active
     * Medicine orientation because an Emergency episode opens Care and
     * Medicine in parallel at arrival regardless of routing_mode
     * (PlanEpisodeRoutingAction) — CareCompletionMode::Finish only ever
     * describes the Care side of the pathway, never the whole episode.
     */
    private function settleAdministrativelyIfPathwayComplete(Episode $episode): void
    {
        if ($episode->administrative_status !== EpisodeAdministrativeStatus::InCare) {
            return;
        }

        $hasActiveMedicineOrientation = EpisodeOrientation::query()
            ->where('active_key', $episode->getKey().':'.CatalogModule::Medicine->value)
            ->exists();

        if ($hasActiveMedicineOrientation) {
            return;
        }

        // ADR-162 — des soins demandés depuis le séjour se terminent, le
        // patient reste au lit : le passage n'attend pas encore sa sortie.
        if (HospitalStay::query()
            ->where('episode_id', $episode->getKey())
            ->where('status', HospitalStayStatus::Active->value)
            ->exists()) {
            return;
        }

        $episode->administrative_status = EpisodeAdministrativeStatus::PendingSettlement;
        $episode->save();
    }

    /**
     * A Care orientation opened by a doctor's CareOrder (Phase B) follows
     * its own, independent completion rule instead of CareWorkflow: the
     * original arrival routing plan has nothing to say about a visit it
     * never planned. requires_return_to_medicine — decided by the doctor at
     * order time, never re-asked of the nurse — governs it entirely.
     */
    private function completeCareOrderPathway(
        EpisodeOrientation $locked,
        CareOrder $careOrder,
        User $actor,
    ): EpisodeOrientation {
        $procedureCount = $locked->episode->careRecord?->procedures->count() ?? 0;

        $careOrder->load(['items.careRecordProcedures']);

        // ADR-112 — si le médecin a retiré tous les actes demandés, il n'y a
        // plus rien à réaliser : exiger un acte obligerait à en inventer un.
        $everythingWithdrawn = $careOrder->items->every(fn ($item) => $item->isCancelled());

        if ($procedureCount === 0 && ! $everythingWithdrawn) {
            throw ValidationException::withMessages([
                'procedures' => 'Enregistrez au moins un acte réellement réalisé avant de terminer les soins.',
            ]);
        }

        if ($careOrder->hasUnresolvedItems()) {
            throw ValidationException::withMessages([
                'care_order' => 'Un acte demandé reste à réaliser ou à marquer non réalisé.',
            ]);
        }

        $locked->complete($actor);
        $careOrder->status = CareOrderStatus::Completed;
        $careOrder->completed_at = now();
        $careOrder->save();

        if ($careOrder->requires_return_to_medicine) {
            $this->createOrientation->execute(
                $locked->episode,
                CatalogModule::Care,
                CatalogModule::Medicine,
                $actor,
                'Retour vers Médecine demandé par l’ordre de soins.',
            );
        } else {
            $this->settleAdministrativelyIfPathwayComplete($locked->episode);
        }

        return $locked->fresh(['episode.patient']);
    }
}
