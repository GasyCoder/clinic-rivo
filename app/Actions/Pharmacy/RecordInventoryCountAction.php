<?php

namespace App\Actions\Pharmacy;

use App\Enums\PharmacyStockAdjustmentType;
use App\Models\MedicineLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — validates a counting sheet. Every lot whose count differs goes
 * through the existing « Inventaire » adjustment (reservations protected,
 * immutable movement, alerts refreshed); a lot already matching its count
 * creates nothing. One refused lot cancels the whole sheet.
 */
class RecordInventoryCountAction
{
    public function __construct(private readonly AdjustMedicineStockAction $adjust) {}

    /**
     * @param  array<int, array{lot_uuid: string, counted_quantity: int|string}>  $counts
     * @return array{adjusted: int, unchanged: int}
     */
    public function execute(array $counts, string $reason, User $actor): array
    {
        return DB::transaction(function () use ($counts, $reason, $actor): array {
            $adjusted = 0;
            $unchanged = 0;

            foreach (array_values($counts) as $index => $count) {
                $lot = MedicineLot::query()
                    ->with('medicine.catalogItem:id,name')
                    ->where('uuid', $count['lot_uuid'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $count['counted_quantity'] === (int) $lot->quantity_on_hand) {
                    $unchanged++;

                    continue;
                }

                try {
                    $this->adjust->execute([
                        'lot_uuid' => $lot->uuid,
                        'type' => PharmacyStockAdjustmentType::Inventory->value,
                        'counted_quantity' => (int) $count['counted_quantity'],
                        'reason' => $reason,
                    ], $actor);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        "counts.{$index}.counted_quantity" => sprintf(
                            '%s, lot %s : %s',
                            $lot->medicine->catalogItem?->name ?? 'Médicament',
                            $lot->lot_number,
                            collect($exception->errors())->flatten()->first(),
                        ),
                    ]);
                }

                $adjusted++;
            }

            return ['adjusted' => $adjusted, 'unchanged' => $unchanged];
        });
    }
}
