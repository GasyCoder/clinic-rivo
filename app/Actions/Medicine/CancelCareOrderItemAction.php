<?php

namespace App\Actions\Medicine;

use App\Enums\CareOrderStatus;
use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodePriority;
use App\Models\CareOrder;
use App\Models\CareOrderItem;
use App\Models\EpisodeOrientation;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le médecin retire un acte demandé aux Soins.
 *
 * Tant que la demande est en cours — en attente ou déjà prise par les
 * Soins — le médecin peut retirer un acte qui n'a pas été réalisé, y compris
 * un acte que l'infirmier a marqué « Non réalisé ». Un acte réalisé, même en
 * partie, ne se retire jamais : il a eu lieu. Une demande terminée ne se
 * modifie plus (ADR-112, amendement du 2026-09-18).
 *
 * Retirer n'est pas supprimer (ADR-010) : la ligne reste, avec son auteur,
 * sa date et son motif. Quand plus rien n'est demandé, la demande est
 * annulée et la file Soins qu'elle avait ouverte se referme.
 */
class CancelCareOrderItemAction
{
    /**
     * Aucun motif libre n'est demandé (comme l'annulation d'un diagnostic,
     * ADR-035) : l'auteur et la date suffisent à tracer le geste.
     */
    public const DEFAULT_REASON = 'Retiré par le médecin pendant la consultation.';

    public function __construct(private readonly Auditor $auditor) {}

    public function execute(
        EpisodeOrientation $medicineOrientation,
        CareOrderItem $item,
        ?string $reason,
        User $actor,
    ): CareOrderItem {
        $reason = trim((string) $reason) ?: self::DEFAULT_REASON;

        return DB::transaction(function () use ($medicineOrientation, $item, $reason, $actor): CareOrderItem {
            $locked = CareOrderItem::query()
                ->with(['careOrder.consultation', 'careOrder.careOrientation.acceptedBy:id,name', 'careRecordProcedures'])
                ->lockForUpdate()
                ->findOrFail($item->getKey());

            $careOrder = $locked->careOrder;
            $consultation = $careOrder->consultation;

            if ($consultation->episode_orientation_id !== $medicineOrientation->getKey()) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'Cet acte n’a pas été demandé depuis cette consultation.',
                ]);
            }

            if (! $consultation->isEditable()) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'La consultation est clôturée : la demande ne peut plus être modifiée.',
                ]);
            }

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'Cet acte a déjà été retiré.',
                ]);
            }

            $careOrientation = $careOrder->careOrientation;

            if ($careOrder->status !== CareOrderStatus::Pending) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'Cette demande de soins est terminée : elle ne peut plus être modifiée.',
                ]);
            }

            if ((float) $locked->realizedQuantity() > 0) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'Cet acte a déjà été réalisé aux Soins : il ne peut plus être retiré.',
                ]);
            }

            $locked->update([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancel_reason' => $reason,
            ]);

            $orderCancelled = $careOrder->items()->whereNull('cancelled_at')->doesntExist();

            // Tant que personne n'a pris le patient, la demande entière est
            // annulée et sa file se referme. Si les Soins l'ont déjà prise,
            // la demande reste ouverte : c'est l'infirmier qui termine sa
            // prise en charge, et plus aucun acte ne l'y oblige.
            if ($orderCancelled && $careOrientation->status === EpisodeOrientationStatus::Pending) {
                $careOrder->update(['status' => CareOrderStatus::Cancelled]);
                $this->closeCareQueueIfNothingLeft($careOrientation, $actor);
            }

            $this->auditor->record(
                'care_order.item.cancel',
                $locked,
                [
                    'status' => 'CANCELLED',
                    'act' => $locked->catalog_item_name_snapshot,
                    'order_cancelled' => $orderCancelled,
                ],
                ['status' => 'REQUESTED'],
                $reason,
                'clinical_flow',
                $actor,
            );

            return $locked->fresh();
        });
    }

    /**
     * Referme la file Soins seulement si elle n'existait que pour des ordres
     * de soins désormais tous retirés. Une file ouverte par le plan
     * d'arrivée, ou celle d'une urgence (ADR-021, ADR-056), n'appartient pas
     * à cette demande : elle reste ouverte.
     */
    private function closeCareQueueIfNothingLeft(EpisodeOrientation $careOrientation, User $actor): void
    {
        $locked = EpisodeOrientation::query()->with('episode')->lockForUpdate()->findOrFail($careOrientation->getKey());

        if ($locked->status !== EpisodeOrientationStatus::Pending
            || $locked->source_module !== CatalogModule::Medicine
            || $locked->reason !== CreateCareOrderAction::ORIENTATION_REASON
            || $locked->episode->priority === EpisodePriority::Emergency) {
            return;
        }

        $stillOrdered = CareOrder::query()
            ->where('care_orientation_id', $locked->getKey())
            ->where('status', CareOrderStatus::Pending)
            ->exists();

        if (! $stillOrdered) {
            $locked->cancel($actor);
        }
    }
}
