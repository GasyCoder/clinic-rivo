<?php

namespace App\Actions\Care;

use App\Enums\CatalogModule;
use App\Enums\EpisodeOrientationStatus;
use App\Models\CareOrderItem;
use App\Models\User;
use App\Support\CareHandlerGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An explicit decline, kept in the same clinical history as a realized act
 * — never a silent absence. No billing implication: only a recorded
 * CareRecordProcedure can ever lead to a BillableItem.
 */
class MarkCareOrderItemNotPerformedAction
{
    public function execute(CareOrderItem $item, string $reason, User $actor): CareOrderItem
    {
        return DB::transaction(function () use ($item, $reason, $actor): CareOrderItem {
            $locked = CareOrderItem::query()
                ->with(['careOrder.careOrientation', 'careRecordProcedures'])
                ->lockForUpdate()
                ->findOrFail($item->getKey());

            $orientation = $locked->careOrder->careOrientation;

            if ($orientation->destination_module !== CatalogModule::Care) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'Cette orientation ne concerne pas le service Soins.',
                ]);
            }

            // Corrigeable après le transfert, comme la fiche elle-même.
            CareHandlerGuard::ensureEditable($orientation, 'care_order_item');

            CareHandlerGuard::ensureWorkable($orientation, $actor, 'care_order_item');

            if ($locked->not_performed_at !== null) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'Cet acte est déjà marqué non réalisé.',
                ]);
            }

            if ((float) $locked->realizedQuantity() > 0) {
                throw ValidationException::withMessages([
                    'care_order_item' => 'Cet acte a déjà été partiellement réalisé.',
                ]);
            }

            $locked->update([
                'not_performed_at' => now(),
                'not_performed_reason' => trim($reason),
                'not_performed_by' => $actor->getKey(),
            ]);

            return $locked->fresh();
        });
    }
}
