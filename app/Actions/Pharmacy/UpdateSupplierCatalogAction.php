<?php

namespace App\Actions\Pharmacy;

use App\Models\SupplierCatalog;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — corrects what is written about a catalog: its date and its note.
 * The file itself is never replaced; a new tariff is a new catalog, so the
 * previous one stays in the folder's history (ADR-097).
 */
class UpdateSupplierCatalogAction
{
    /** @param array{catalog_date?: ?string, notes?: ?string} $data */
    public function execute(SupplierCatalog $catalog, array $data, CatalogActor $actor): SupplierCatalog
    {
        if ($actor->cannot('supplier_catalogs.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier ce catalogue.');
        }

        if ($catalog->trashed()) {
            throw ValidationException::withMessages(['catalog' => 'Restaurez le catalogue avant de le modifier.']);
        }

        $catalog->update([
            'catalog_date' => $data['catalog_date'] ?? null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'updated_by' => $actor->localUserId() ?? $catalog->updated_by,
        ]);

        return $catalog;
    }
}
