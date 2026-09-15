<?php

namespace App\Actions\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-097 — the versioned price a supplier currently quotes for a medicine
 * (spec §5/§6). Copies SetCatalogTariffAction's exact close-old/open-new
 * mechanism: never mutates a past quote, closes it and opens a new row.
 * Multiple suppliers can each hold their own simultaneous CURRENT offer for
 * the same medicine (spec §5's "plusieurs fournisseurs à des prix
 * différents") — the uniqueness constraint is per (medicine, supplier)
 * pair, not per medicine alone.
 */
class SetMedicineSupplierOfferAction
{
    public function execute(
        Medicine $medicine,
        MedicineSupplier $supplier,
        string|int $quotedPrice,
        string $reason,
        User $actor,
        ?string $supplierReference = null,
        ?SupplierCatalogItem $sourceCatalogItem = null,
    ): MedicineSupplierOffer {
        return DB::transaction(function () use ($medicine, $supplier, $quotedPrice, $reason, $actor, $supplierReference, $sourceCatalogItem) {
            $medicine = Medicine::query()->lockForUpdate()->findOrFail($medicine->id);

            $current = MedicineSupplierOffer::query()
                ->where('medicine_id', $medicine->id)
                ->where('medicine_supplier_id', $supplier->id)
                ->where('active_key', 'CURRENT')
                ->lockForUpdate()
                ->first();

            $permission = $current ? 'medicine_supplier_offers.update' : 'medicine_supplier_offers.create';

            if ($actor->cannot($permission)) {
                throw new AuthorizationException('Vous ne pouvez pas modifier ce prix fournisseur.');
            }

            $priceMinor = Money::toMinor($quotedPrice);

            if ($priceMinor <= 0) {
                throw ValidationException::withMessages([
                    'quoted_price' => 'Le prix fournisseur doit être supérieur à zéro.',
                ]);
            }

            if ($current && Money::toMinor($current->quoted_price) === $priceMinor) {
                throw ValidationException::withMessages([
                    'quoted_price' => 'Ce prix est identique au prix fournisseur actuel.',
                ]);
            }

            $effectiveAt = now();

            if ($current) {
                $current->fill([
                    'effective_until' => $effectiveAt,
                    'active_key' => null,
                    'ended_by' => $actor->getKey(),
                ])->save();
            }

            // ADR-098 — the offer is the source of truth for "who supplies
            // this medicine"; the plain medicine_supplier link is kept in
            // step so the two can never tell different stories.
            $medicine->suppliers()->syncWithoutDetaching([$supplier->getKey()]);

            return MedicineSupplierOffer::query()->create([
                'medicine_id' => $medicine->getKey(),
                'medicine_supplier_id' => $supplier->getKey(),
                'supplier_catalog_item_id' => $sourceCatalogItem?->getKey(),
                'supplier_reference' => $supplierReference,
                'quoted_price' => Money::fromMinor($priceMinor),
                'currency' => 'MGA',
                'effective_from' => $effectiveAt,
                'active_key' => 'CURRENT',
                'change_reason' => trim($reason),
                'created_by' => $actor->getKey(),
            ]);
        });
    }
}
