<?php

namespace App\Services\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * ADR-179 — chez qui d'autre trouver un article que ce fournisseur ne livre pas.
 *
 * Le rapprochement porte sur **le même médicament**, jamais sur une famille :
 * le référentiel ne connaît aucune équivalence thérapeutique, et remplacer un
 * produit par « un autre de la même famille » serait une décision de
 * substitution que rien ici ne permet de fonder (ADR-052). Ce qui est proposé
 * est donc uniquement ce que la clinique sait déjà — les prix d'achat
 * versionnés et les catalogues actifs (ADR-097).
 *
 * Le service ne commande rien : il répond « qui d'autre le propose, et à
 * quel prix ». Le choix reste celui de l'acheteur, un prix plus bas pouvant
 * venir d'un fournisseur en rupture ou plus lent.
 */
class SupplierAlternatives
{
    /**
     * @param  Collection<int, Medicine>|array<int, Medicine>  $medicines
     * @return array<string, array<int, array<string, mixed>>> par UUID de médicament
     */
    public function forMedicines(iterable $medicines, MedicineSupplier $exclude, User $user): array
    {
        $medicines = collect($medicines);
        $ids = $medicines->pluck('id')->filter()->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        $seeCost = $user->can('stock.cost.view');

        // Ce que d'autres fournisseurs cotent aujourd'hui pour ces produits.
        $offers = MedicineSupplierOffer::query()
            ->where('active_key', 'CURRENT')
            ->whereIn('medicine_id', $ids)
            ->where('medicine_supplier_id', '!=', $exclude->getKey())
            ->with('supplier:id,uuid,name')
            ->get()
            ->groupBy('medicine_id');

        // Et ce que leurs catalogues actifs proposent sans prix rattaché : un
        // fournisseur peut lister le produit sans que la clinique lui ait
        // encore enregistré un prix d'achat.
        $catalogued = SupplierCatalogItem::query()
            ->whereIn('linked_medicine_id', $ids)
            ->whereHas('catalog', fn ($query) => $query
                ->where('active_key', 'ACTIVE')
                ->where('medicine_supplier_id', '!=', $exclude->getKey()))
            ->with(['catalog:id,medicine_supplier_id', 'catalog.supplier:id,uuid,name'])
            ->get()
            ->groupBy('linked_medicine_id');

        return $medicines
            ->mapWithKeys(fn (Medicine $medicine) => [
                $medicine->uuid => $this->merge(
                    $offers->get($medicine->getKey(), collect()),
                    $catalogued->get($medicine->getKey(), collect()),
                    $seeCost,
                ),
            ])
            ->filter(fn (array $rows) => $rows !== [])
            ->all();
    }

    /**
     * @param  Collection<int, MedicineSupplierOffer>  $offers
     * @param  Collection<int, SupplierCatalogItem>  $catalogued
     * @return array<int, array<string, mixed>>
     */
    private function merge(Collection $offers, Collection $catalogued, bool $seeCost): array
    {
        $rows = $offers
            ->filter(fn (MedicineSupplierOffer $offer) => $offer->supplier !== null)
            ->map(fn (MedicineSupplierOffer $offer) => [
                'supplier_uuid' => $offer->supplier->uuid,
                'supplier_name' => $offer->supplier->name,
                'supplier_reference' => $offer->supplier_reference,
                // ADR-174 — le prix d'achat reste confidentiel.
                'quoted_price' => $seeCost && filled($offer->quoted_price)
                    ? Money::normalize((string) $offer->quoted_price)
                    : null,
                'has_price' => filled($offer->quoted_price),
            ]);

        $known = $rows->pluck('supplier_uuid')->flip();

        $fromCatalog = $catalogued
            ->filter(fn (SupplierCatalogItem $item) => $item->catalog?->supplier !== null)
            ->reject(fn (SupplierCatalogItem $item) => $known->has($item->catalog->supplier->uuid))
            ->unique(fn (SupplierCatalogItem $item) => $item->catalog->supplier->uuid)
            ->map(fn (SupplierCatalogItem $item) => [
                'supplier_uuid' => $item->catalog->supplier->uuid,
                'supplier_name' => $item->catalog->supplier->name,
                'supplier_reference' => $item->reference,
                'quoted_price' => $seeCost && filled($item->supplier_price)
                    ? Money::normalize((string) $item->supplier_price)
                    : null,
                'has_price' => filled($item->supplier_price),
            ]);

        // Le moins cher d'abord quand le prix est lisible ; sinon par nom,
        // pour que la liste reste stable d'un compte à l'autre.
        return $rows->concat($fromCatalog)
            ->sortBy([
                fn (array $row) => $row['quoted_price'] === null ? 1 : 0,
                fn (array $row) => $row['quoted_price'] === null ? 0 : (float) $row['quoted_price'],
                fn (array $row) => mb_strtolower($row['supplier_name']),
            ])
            ->values()
            ->all();
    }
}
