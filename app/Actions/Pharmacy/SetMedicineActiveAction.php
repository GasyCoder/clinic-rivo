<?php

namespace App\Actions\Pharmacy;

use App\Models\Medicine;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\MedicineStockAlertService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — « archiving » a medicine deactivates it; it is never soft-deleted.
 * Prescriptions, deliveries, lots and movements keep pointing to the record
 * (ADR-010): only new sales, orders and entries stop proposing it. The reason
 * is kept on the record and cleared when the medicine is reactivated.
 */
class SetMedicineActiveAction
{
    public function __construct(private readonly MedicineStockAlertService $alerts) {}

    public function execute(Medicine $medicine, bool $active, ?string $reason, CatalogActor $actor): Medicine
    {
        $permission = $active ? 'medicines.restore' : 'medicines.delete';

        if ($actor->cannot($permission)) {
            throw new AuthorizationException($active
                ? 'Vous ne pouvez pas réactiver ce médicament.'
                : 'Vous ne pouvez pas désactiver ce médicament.');
        }

        return DB::transaction(function () use ($medicine, $active, $reason, $actor): Medicine {
            $medicine = Medicine::query()->with('lots')->lockForUpdate()->findOrFail($medicine->id);

            if ($medicine->active === $active) {
                throw ValidationException::withMessages([
                    'medicine' => $active ? 'Ce médicament est déjà actif.' : 'Ce médicament est déjà désactivé.',
                ]);
            }

            if (! $active) {
                if (mb_strlen(trim((string) $reason)) < 3) {
                    throw ValidationException::withMessages(['reason' => 'Le motif est obligatoire (3 caractères au moins).']);
                }

                // Units reserved for a prescription or a counter sale must still be
                // delivered: deactivating now would strand them.
                $reserved = $medicine->lots->sum(fn ($lot) => $lot->reservedQuantity());

                if ($reserved > 0) {
                    throw ValidationException::withMessages([
                        'medicine' => "{$reserved} unité(s) sont réservées pour des ordonnances ou des ventes en cours : délivrez-les ou annulez-les avant de désactiver ce médicament.",
                    ]);
                }
            }

            $medicine->forceFill([
                'active' => $active,
                'delete_reason' => $active ? null : trim((string) $reason),
                'updated_by' => $actor->localUserId() ?? $medicine->updated_by,
            ])->save();

            $this->alerts->synchronize($medicine);

            return $medicine;
        });
    }
}
