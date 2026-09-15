<?php

namespace App\Actions\Pharmacy;

use App\Enums\PurchaseOrderStatus;
use App\Models\MedicineSupplier;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — archiving hides a supplier from every new choice; its catalogs,
 * prices, lots, orders and invoices stay attached (ADR-009/010).
 *
 * A supplier still expecting goods cannot be archived: the reception of an
 * open order records a stock entry against that supplier, which an archived
 * supplier would refuse. The pharmacy finishes or cancels the order first.
 */
class ArchiveMedicineSupplierAction
{
    public function execute(MedicineSupplier $supplier, string $reason, CatalogActor $actor): MedicineSupplier
    {
        if ($actor->cannot('medicine_suppliers.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver ce fournisseur.');
        }

        return DB::transaction(function () use ($supplier, $reason): MedicineSupplier {
            $supplier = MedicineSupplier::query()->lockForUpdate()->findOrFail($supplier->id);

            $openOrders = $supplier->purchaseOrders()
                ->whereIn('status', [
                    PurchaseOrderStatus::Draft->value,
                    PurchaseOrderStatus::Ordered->value,
                    PurchaseOrderStatus::PartiallyReceived->value,
                ])
                ->count();

            if ($openOrders > 0) {
                throw ValidationException::withMessages([
                    'reason' => "{$supplier->name} a {$openOrders} commande(s) en cours (brouillon ou en attente de réception). "
                        .'Terminez ou annulez ces commandes avant d’archiver le fournisseur.',
                ]);
            }

            $supplier->delete_reason = trim($reason);
            $supplier->delete();

            return $supplier;
        });
    }
}
