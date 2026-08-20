<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared domain entry point for future Medicine/Laboratory/Pharmacy/
 * Surgery/Care modules. Their own authorized Actions call this service;
 * no business module receives a payment capability through it.
 */
class RecordBillableItemAction
{
    /**
     * @param  array{catalog_item_uuid: string, quantity: int|string, payment_required_before_fulfillment?: bool}  $data
     */
    public function execute(Episode $episode, array $data, User $actor, ?Model $source = null): BillableItem
    {
        return DB::transaction(function () use ($episode, $data, $actor, $source) {
            $item = CatalogItem::query()
                ->where('uuid', $data['catalog_item_uuid'] ?? '')
                ->where('billable', true)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'La prestation sélectionnée est indisponible ou archivée.',
                ]);
            }

            $tariff = $item->currentTariff()->lockForUpdate()->first();

            if (! $tariff) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'Cette prestation ne possède aucun tarif actif.',
                ]);
            }

            $quantityMinor = Money::toMinor($data['quantity']);
            $unitPriceMinor = Money::toMinor($tariff->amount);
            $totalMinor = Money::multiply($data['quantity'], $tariff->amount);

            if ($quantityMinor <= 0 || $unitPriceMinor <= 0 || $totalMinor > 999_999_999_999_999) {
                throw ValidationException::withMessages([
                    'amount' => 'La quantité et le tarif doivent produire un montant valide supérieur à zéro.',
                ]);
            }

            return BillableItem::create([
                'episode_id' => $episode->id,
                'source_module' => $item->module->value,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'source_uuid' => $source?->getAttribute('uuid'),
                'catalog_item_id' => $item->id,
                'catalog_tariff_id' => $tariff->id,
                // Snapshot obligatoire : les changements de tarif futurs ne
                // modifient jamais une prestation/facture déjà créée.
                'description' => $item->name,
                'quantity' => Money::normalize($data['quantity']),
                'unit_price' => Money::fromMinor($unitPriceMinor),
                'total_amount' => Money::fromMinor($totalMinor),
                'currency' => $tariff->currency,
                'payment_required_before_fulfillment' => (bool) ($data['payment_required_before_fulfillment'] ?? false),
                'status' => BillableItemStatus::Pending,
                'created_by' => $actor->id,
            ]);
        });
    }
}
