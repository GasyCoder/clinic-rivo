<?php

namespace App\Services\Pharmacy;

use App\Enums\PharmacyStockMovementType;
use App\Models\Medicine;
use App\Models\MedicineLot;
use App\Models\PharmacyStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicineStockImportService
{
    /**
     * @param  array<int, array<string, int|string|null>>  $rows
     * @return array{rows: int, quantity: int, created_lots: int, updated_lots: int}
     */
    public function import(
        array $rows,
        string $externalActorUuid,
        string $externalActorName,
        string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($rows, $externalActorUuid, $externalActorName, $idempotencyKey): array {
            $createdLots = 0;
            $updatedLots = 0;
            $totalQuantity = 0;

            foreach ($rows as $index => $row) {
                $medicine = Medicine::query()
                    ->where('active', true)
                    ->whereHas('catalogItem', fn ($query) => $query
                        ->where('code', $row['code_medicament']))
                    ->lockForUpdate()
                    ->first();

                if (! $medicine) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.code_medicament" => sprintf(
                            'Le médicament actif « %s » n’existe pas dans le référentiel du site.',
                            $row['code_medicament'],
                        ),
                    ]);
                }

                $lot = MedicineLot::query()
                    ->where('medicine_id', $medicine->id)
                    ->where('lot_number', $row['numero_lot'])
                    ->lockForUpdate()
                    ->first();

                if ($row['operation'] === 'STOCK_INITIAL' && $lot !== null) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.numero_lot" => sprintf(
                            'Le lot « %s » existe déjà : utilisez ENTREE, jamais STOCK_INITIAL.',
                            $row['numero_lot'],
                        ),
                    ]);
                }

                if ($lot !== null && $lot->expires_at->toDateString() !== $row['date_peremption']) {
                    throw ValidationException::withMessages([
                        "rows.{$index}.date_peremption" => sprintf(
                            'La péremption du lot « %s » diffère de celle déjà enregistrée (%s).',
                            $row['numero_lot'],
                            $lot->expires_at->format('d/m/Y'),
                        ),
                    ]);
                }

                $quantity = (int) $row['quantite'];
                $balanceBefore = $lot?->quantity_on_hand ?? 0;
                $balanceAfter = $balanceBefore + $quantity;

                if ($lot === null) {
                    $lot = MedicineLot::query()->create([
                        'medicine_id' => $medicine->id,
                        'lot_number' => $row['numero_lot'],
                        'received_at' => $row['date_reception'],
                        'expires_at' => $row['date_peremption'],
                        'quantity_on_hand' => $balanceAfter,
                        'active' => true,
                        'created_by' => null,
                        'updated_by' => null,
                        'external_created_by_uuid' => $externalActorUuid,
                        'external_created_by_name' => $externalActorName,
                        'external_updated_by_uuid' => $externalActorUuid,
                        'external_updated_by_name' => $externalActorName,
                    ]);
                    $createdLots++;
                } else {
                    if (! $lot->active) {
                        throw ValidationException::withMessages([
                            "rows.{$index}.numero_lot" => sprintf('Le lot « %s » est inactif.', $row['numero_lot']),
                        ]);
                    }

                    $lot->update([
                        'received_at' => $lot->received_at ?? $row['date_reception'],
                        'quantity_on_hand' => $balanceAfter,
                        'updated_by' => null,
                        'external_updated_by_uuid' => $externalActorUuid,
                        'external_updated_by_name' => $externalActorName,
                    ]);
                    $updatedLots++;
                }

                PharmacyStockMovement::query()->create([
                    'medicine_lot_id' => $lot->id,
                    'type' => $row['operation'] === 'STOCK_INITIAL'
                        ? PharmacyStockMovementType::Opening
                        : PharmacyStockMovementType::Entry,
                    'quantity_delta' => $quantity,
                    'balance_after' => $balanceAfter,
                    'source_key' => sprintf('excel:%s:%d', $idempotencyKey, $index + 1),
                    'reason' => $row['motif'],
                    'occurred_at' => now(),
                    'performed_by' => null,
                    'external_actor_uuid' => $externalActorUuid,
                    'external_actor_name' => $externalActorName,
                ]);
                $totalQuantity += $quantity;
            }

            return [
                'rows' => count($rows),
                'quantity' => $totalQuantity,
                'created_lots' => $createdLots,
                'updated_lots' => $updatedLots,
            ];
        });
    }
}
