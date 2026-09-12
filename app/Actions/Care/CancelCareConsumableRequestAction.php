<?php

namespace App\Actions\Care;

use App\Actions\Billing\CancelBillableItemAction;
use App\Enums\BillableItemStatus;
use App\Enums\CareConsumableRequestStatus;
use App\Models\CareConsumableRequest;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-010/ADR-072 — a declared consumption is never deleted. Cancellation
 * is the supported correction, and only while nothing has physically left
 * the Pharmacy stock: once a lot has moved, the trace stays and the
 * correction belongs to a Pharmacy stock adjustment instead.
 */
class CancelCareConsumableRequestAction
{
    public function __construct(
        private readonly CancelBillableItemAction $cancelBillableItem,
        private readonly Auditor $auditor,
    ) {}

    public function execute(CareConsumableRequest $request, string $reason, User $actor): CareConsumableRequest
    {
        return DB::transaction(function () use ($request, $reason, $actor): CareConsumableRequest {
            $request = CareConsumableRequest::query()
                ->with('lines.billableItem')
                ->lockForUpdate()
                ->findOrFail($request->getKey());
            $reason = trim($reason);

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Le motif d’annulation est obligatoire.',
                ]);
            }

            if (! $request->status->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'request' => 'Une demande déjà servie par la Pharmacie ne peut plus être annulée : corrigez le stock par un ajustement audité.',
                ]);
            }

            foreach ($request->lines as $line) {
                $item = $line->billableItem;

                // An item already carried onto an invoice is not unwound
                // here: only Réception/Caisse may touch an invoiced amount.
                if ($item && $item->status === BillableItemStatus::Pending) {
                    $this->cancelBillableItem->execute($item, $reason, $actor);
                }
            }

            $request->update([
                'status' => CareConsumableRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancellation_reason' => $reason,
            ]);

            $this->auditor->record(
                'care.consumables.cancel',
                entity: $request,
                oldValues: ['status' => CareConsumableRequestStatus::Pending->value],
                newValues: ['status' => CareConsumableRequestStatus::Cancelled->value],
                reason: $reason,
                module: 'care',
                actor: $actor,
            );

            return $request->refresh();
        });
    }
}
