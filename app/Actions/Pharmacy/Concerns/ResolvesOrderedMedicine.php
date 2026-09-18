<?php

namespace App\Actions\Pharmacy\Concerns;

use App\Actions\Pharmacy\CreateMedicineFromSupplierCatalogAction;
use App\Models\Medicine;
use App\Models\MedicineSupplier;
use App\Models\SupplierCatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — an order line names either a medicine the clinic already holds,
 * or a line of the supplier's catalogue it has not taken up yet. Creating
 * and correcting an order resolve it the same way, so the two can never
 * accept different things.
 */
trait ResolvesOrderedMedicine
{
    private ?CreateMedicineFromSupplierCatalogAction $catalogToMedicine = null;

    /** @var array<int, true> les produits déjà placés sur cette commande */
    private array $ordered = [];

    /** @param array{medicine_uuid?: ?string, supplier_catalog_item_uuid?: ?string} $line */
    private function resolveMedicine(array $line, MedicineSupplier $supplier, CatalogActor $actor): Medicine
    {
        if (filled($line['medicine_uuid'] ?? null)) {
            return $this->assertNotAlreadyOrdered(
                Medicine::query()->where('uuid', $line['medicine_uuid'])->firstOrFail(),
            );
        }

        $item = SupplierCatalogItem::query()
            ->where('uuid', $line['supplier_catalog_item_uuid'])
            ->firstOrFail();

        // The line must belong to this supplier: its uuid is public, and
        // ordering another supplier's catalogue row would attach a product
        // to a folder that never proposed it.
        if ($item->catalog()->value('medicine_supplier_id') !== $supplier->getKey()) {
            throw ValidationException::withMessages([
                'lines' => 'Cette ligne de catalogue appartient à un autre fournisseur.',
            ]);
        }

        // One instance for the whole order: it keeps the catalogue it has
        // already read, instead of re-reading it for every line.
        $this->catalogToMedicine ??= app(CreateMedicineFromSupplierCatalogAction::class);

        return $this->assertNotAlreadyOrdered($this->catalogToMedicine->execute($item, $actor));
    }

    /**
     * Un produit ne peut occuper qu'une ligne de la commande.
     *
     * La validation ne peut pas le voir : deux lignes de catalogue sont deux
     * UUID distincts, et ce n'est qu'après résolution qu'on découvre qu'elles
     * désignent le même médicament — un catalogue liste couramment le même
     * produit sous deux références. Sans ce contrôle, l'insertion heurtait la
     * contrainte d'unicité et l'acheteur recevait une erreur SQL.
     *
     * La fusion des quantités n'est pas faite à sa place : les deux lignes
     * peuvent porter des prix différents, et choisir lequel garder est une
     * décision d'achat.
     */
    private function assertNotAlreadyOrdered(Medicine $medicine): Medicine
    {
        $medicine->loadMissing('catalogItem');

        if (isset($this->ordered[$medicine->getKey()])) {
            throw ValidationException::withMessages([
                'lines' => "« {$medicine->catalogItem?->name} » apparaît deux fois dans cette commande : deux lignes du catalogue désignent le même produit. Gardez-en une seule et ajustez sa quantité.",
            ]);
        }

        $this->ordered[$medicine->getKey()] = true;

        return $medicine;
    }
}
