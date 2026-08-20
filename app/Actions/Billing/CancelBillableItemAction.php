<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Models\BillableItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBillableItemAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(BillableItem $item, string $reason, User $actor): BillableItem
    {
        return DB::transaction(function () use ($item, $reason, $actor) {
            $item = BillableItem::query()->lockForUpdate()->findOrFail($item->id);
            $reason = trim($reason);

            if ($item->status !== BillableItemStatus::Pending) {
                throw ValidationException::withMessages([
                    'billable_item' => 'Seule une prestation non encore facturée peut être annulée directement.',
                ]);
            }

            if ($reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Le motif d’annulation est obligatoire.',
                ]);
            }

            $item->fill([
                'status' => BillableItemStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->auditor->record(
                'billing.item.cancel',
                entity: $item,
                oldValues: ['status' => BillableItemStatus::Pending->value],
                newValues: ['status' => BillableItemStatus::Cancelled->value],
                reason: $reason,
                module: 'billing',
                actor: $actor,
            );

            return $item->refresh();
        });
    }
}
