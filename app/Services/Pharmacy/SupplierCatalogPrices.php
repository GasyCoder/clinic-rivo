<?php

namespace App\Services\Pharmacy;

use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Collection;

/**
 * ADR-183 — un prix d'achat venu d'un catalogue fournisseur ne survit pas au
 * retrait de ce catalogue.
 *
 * Rattacher une ligne de catalogue à un médicament crée un prix d'achat
 * versionné (ADR-097). Mettre le catalogue — ou la seule ligne — à la
 * corbeille laissait pourtant ce prix « en cours » : le fournisseur restait
 * proposé dans « Comparer et commander » pour un tarif que plus personne ne
 * tenait pour valable. Le constat du propriétaire, sur Pharmalife : « je l'ai
 * supprimé, il apparaît toujours ».
 *
 * Retirer n'est pas effacer (ADR-010) : le prix est **clos** — daté, libéré —
 * exactement comme le fait déjà « Défaire un rattachement » (ADR-098), et il
 * reste lisible dans l'historique. Restaurer le catalogue le rétablit, en
 * nouvelle version au même montant, seulement si rien ne l'a remplacé
 * entre-temps : une décision prise depuis n'est jamais écrasée.
 */
class SupplierCatalogPrices
{
    /**
     * Clôt les prix en cours qui viennent de ces lignes de catalogue.
     *
     * @param  Collection<int, int>|array<int, int>  $itemIds
     * @return int le nombre de prix clos
     */
    public function withdraw(Collection|array $itemIds, CatalogActor $actor): int
    {
        $itemIds = collect($itemIds)->filter()->values();

        if ($itemIds->isEmpty()) {
            return 0;
        }

        return MedicineSupplierOffer::query()
            ->whereIn('supplier_catalog_item_id', $itemIds)
            ->where('active_key', 'CURRENT')
            ->lockForUpdate()
            ->get()
            ->each(fn (MedicineSupplierOffer $offer) => $offer->fill([
                'effective_until' => now(),
                'active_key' => null,
                'ended_by' => $actor->localUserId(),
                ...$actor->externalAttribution('ended'),
            ])->save())
            ->count();
    }

    /**
     * Rétablit les prix que ces lignes avaient fournis et que leur retrait
     * avait clos.
     *
     * Une ligne ne rétablit son prix que si elle est toujours rattachée à un
     * médicament, que la paire (médicament, fournisseur) n'a plus aucun prix
     * en cours, et que le dernier prix de cette paire venait bien d'elle. Un
     * prix fixé à la main depuis, ou un rattachement défait, restent tels quels.
     *
     * @param  Collection<int, SupplierCatalogItem>  $items  lignes vivantes, catalogue chargé
     * @return int le nombre de prix rétablis
     */
    public function reinstate(Collection $items, CatalogActor $actor): int
    {
        $reinstated = 0;

        foreach ($items as $item) {
            if (! $item->linked_medicine_id || ! $item->catalog) {
                continue;
            }

            $supplierId = $item->catalog->medicine_supplier_id;
            $pair = MedicineSupplierOffer::query()
                ->where('medicine_id', $item->linked_medicine_id)
                ->where('medicine_supplier_id', $supplierId)
                ->lockForUpdate();

            if ((clone $pair)->where('active_key', 'CURRENT')->exists()) {
                continue;
            }

            $last = (clone $pair)->latest('effective_from')->latest('id')->first();

            if (! $last || (int) $last->supplier_catalog_item_id !== (int) $item->getKey()) {
                continue;
            }

            MedicineSupplierOffer::query()->create([
                'medicine_id' => $last->medicine_id,
                'medicine_supplier_id' => $supplierId,
                'supplier_catalog_item_id' => $item->getKey(),
                'supplier_reference' => $last->supplier_reference,
                'quoted_price' => $last->quoted_price,
                'currency' => $last->currency,
                'effective_from' => now(),
                'active_key' => 'CURRENT',
                'change_reason' => 'Catalogue fournisseur restauré : le prix qu’il fournissait est rétabli.',
                'created_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
            ]);
            $reinstated++;
        }

        return $reinstated;
    }
}
