<?php

namespace App\Services\Pharmacy;

use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineLot;
use App\Models\MedicineSupplier;
use App\Models\MedicineSupplierOffer;
use App\Models\SupplierCatalogItem;
use App\Support\Money;
use App\Support\ProductLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What each supplier currently quotes for the same medicine, side by side.
 *
 * Preparing an order meant opening one supplier folder after another to
 * compare prices. Nothing new is stored: this reads the versioned offers
 * (`medicine_supplier_offers`, ADR-097) that already carry every price, and
 * the stock the clinic still holds — so « faut-il commander ? » and « chez
 * qui ? » are answered on the same line.
 */
class SupplierOfferComparison
{
    /**
     * @param  array<int, string>  $supplierUuids  restrict to these suppliers; all active ones when empty
     * @return array<string, mixed>
     */
    public function forSite(array $supplierUuids = []): array
    {
        // An archived supplier is soft-deleted, so the default scope already
        // keeps it out: nothing is ordered from a folder that was closed.
        $suppliers = MedicineSupplier::query()
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'code']);

        $selected = $supplierUuids === []
            ? $suppliers
            : $suppliers->whereIn('uuid', $supplierUuids)->values();

        $offers = MedicineSupplierOffer::query()
            ->where('active_key', 'CURRENT')
            ->whereIn('medicine_supplier_id', $selected->pluck('id'))
            ->with([
                'medicine.catalogItem:id,code,name,unit',
                'medicine.category:id,name',
                'medicine.lots' => fn ($query) => $query->withReservedQuantity(),
            ])
            ->get();

        $today = CarbonImmutable::today();

        $rows = $offers
            ->groupBy('medicine_id')
            ->map(function (Collection $group) use ($selected, $today): array {
                /** @var Medicine $medicine */
                $medicine = $group->first()->medicine;

                return [
                    'key' => 'medicine:'.$medicine->uuid,
                    'medicine_uuid' => $medicine->uuid,
                    'code' => $medicine->catalogItem?->code,
                    'name' => $medicine->catalogItem?->name,
                    'unit' => $medicine->catalogItem?->unit,
                    // La famille que la clinique a donnée à ce produit.
                    'family' => $medicine->category?->name,
                    'in_clinic_catalog' => true,
                    'product_group' => ProductLabel::normalize($medicine->catalogItem?->name),
                    'in_stock' => $medicine->lots
                        ->filter(fn (MedicineLot $lot) => $lot->isUsableOn($today))
                        ->sum(fn (MedicineLot $lot) => $lot->availableQuantity()),
                    'minimum_stock' => $medicine->minimum_stock,
                    'quotes' => $group->map(fn (MedicineSupplierOffer $offer): array => [
                        'key' => 'offer:'.$offer->id,
                        'supplier_uuid' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->uuid,
                        'supplier_name' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->name,
                        'supplier_catalog_item_uuid' => null,
                        'supplier_catalog_uuid' => null,
                        'reference' => null,
                        'can_link' => false,
                        'price' => Money::normalize((string) $offer->quoted_price),
                        'effective_from' => $offer->effective_from?->toDateString(),
                    ])->values()->all(),
                ];
            })
            ->keyBy('product_group');

        // The supplier's active catalogue lines the clinic has not taken up
        // yet. Without them, a supplier whose catalogue is imported but not
        // yet linked offered nothing here, although the order form of its
        // own folder lists every line. Same product name, same row: the
        // order resolves them to one medicine (ADR-098), so they compare.
        $clinicFamilies = $this->clinicFamilies();

        foreach ($this->unlinkedCatalogLines($selected) as [$supplier, $item]) {
            $group = ProductLabel::normalize($item->medicine_label);
            $row = $rows->get($group) ?? [
                'key' => 'catalog:'.$group,
                'medicine_uuid' => null,
                'code' => $item->reference,
                'name' => $item->medicine_label,
                'unit' => $item->presentation,
                'family' => null,
                'in_clinic_catalog' => false,
                'product_group' => $group,
                'in_stock' => null,
                'minimum_stock' => null,
                'quotes' => [],
            ];

            // La famille que le fournisseur déclare, écrite comme celle de la
            // clinique quand elles se ressemblent (ADR-098) ; celle d'un produit
            // déjà tenu n'est jamais remplacée par celle d'un fichier.
            $row['family'] ??= $this->familyOf($item->family_label, $clinicFamilies);

            $row['quotes'][] = [
                'key' => 'catalog-item:'.$item->uuid,
                'supplier_uuid' => $supplier->uuid,
                'supplier_name' => $supplier->name,
                'supplier_catalog_item_uuid' => $item->uuid,
                // Le rattachement se fait sur la ligne, dans son fichier.
                'supplier_catalog_uuid' => $item->catalog->uuid,
                'reference' => $item->reference,
                'price' => filled($item->supplier_price) ? Money::normalize((string) $item->supplier_price) : null,
                // Rattacher crée le prix d'achat : sans prix, il n'y a rien à
                // créer, et l'action le refuse (ADR-098).
                'can_link' => filled($item->supplier_price),
                'effective_from' => null,
            ];

            $rows->put($group, $row);
        }

        $reconciliations = $this->reconciliations($rows);

        $medicines = $rows
            ->map(function (array $row) use ($reconciliations): array {
                // Cheapest first, so the comparison reads itself; the
                // choice stays the buyer's — a cheaper supplier may be out
                // of stock or slower, and the screen never decides. A line
                // without a price comes last: it cannot be compared.
                $quotes = collect($row['quotes'])
                    ->sortBy(fn (array $quote) => $quote['price'] === null ? PHP_FLOAT_MAX : (float) $quote['price'])
                    ->values();

                return [
                    ...$row,
                    'best_price' => $quotes->first(fn (array $quote) => $quote['price'] !== null)['price'] ?? null,
                    'quotes' => $quotes->all(),
                    // ADR-181 — « c'est peut-être le produit que la clinique
                    // tient déjà sous un autre nom ». Jamais une décision.
                    'suggestions' => $reconciliations[$row['product_group']] ?? [],
                ];
            })
            ->sortBy(fn (array $row) => mb_strtolower((string) $row['name']))
            ->values()
            ->all();

        $catalogCounts = collect($this->unlinkedCatalogLines($selected))
            ->countBy(fn (array $pair) => $pair[0]->id);

        return [
            'suppliers' => $suppliers->map(fn (MedicineSupplier $supplier): array => [
                'uuid' => $supplier->uuid,
                'name' => $supplier->name,
                'code' => $supplier->code,
                'offers_count' => $offers->where('medicine_supplier_id', $supplier->id)->count()
                    + ($catalogCounts[$supplier->id] ?? 0),
            ])->all(),
            'medicines' => $medicines,
            // Combien de lignes de catalogue ressemblent à un produit déjà
            // tenu par la clinique : sans ce compte, personne ne sait qu'il y
            // a des prix à rapprocher avant de commander.
            'to_reconcile' => count($reconciliations),
        ];
    }

    /**
     * Les familles de la clinique, par nom normalisé : un fournisseur qui
     * écrit « SERINGUES » range sa ligne sous « Seringues ».
     *
     * @return Collection<string, string>
     */
    private function clinicFamilies(): Collection
    {
        return MedicineCategory::query()
            ->orderBy('name')
            ->pluck('name')
            ->mapWithKeys(fn (string $name) => [ProductLabel::normalize($name) => $name]);
    }

    /** @param  Collection<string, string>  $clinicFamilies */
    private function familyOf(?string $declared, Collection $clinicFamilies): ?string
    {
        $declared = trim((string) $declared);

        if ($declared === '') {
            return null;
        }

        return $clinicFamilies->get(ProductLabel::normalize($declared), $declared);
    }

    /** Au-delà, la liste cesse d'aider : on en propose peu, et de bons. */
    private const MAX_SUGGESTIONS = 3;

    /**
     * Les lignes de catalogue fournisseur qui désignent peut-être un produit
     * déjà au catalogue de la clinique, écrit autrement.
     *
     * Sans rapprochement, ces deux libellés restent deux lignes : leurs prix
     * ne se comparent pas, et commander la ligne du fournisseur créerait un
     * second produit, avec son propre stock. Le rapprochement est donc le seul
     * moyen de comparer — mais il reste un geste humain : la règle propose,
     * l'acheteur confirme en lisant les deux libellés.
     *
     * @param  Collection<string, array<string, mixed>>  $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function reconciliations(Collection $rows): array
    {
        $unlinked = $rows->filter(fn (array $row) => $row['in_clinic_catalog'] === false);

        if ($unlinked->isEmpty()) {
            return [];
        }

        $catalogue = Medicine::query()
            ->where('active', true)
            ->with('catalogItem:id,code,name,unit')
            ->get()
            ->map(fn (Medicine $medicine): array => [
                'medicine_uuid' => $medicine->uuid,
                'name' => $medicine->catalogItem?->name,
                'code' => $medicine->catalogItem?->code,
                'unit' => $medicine->catalogItem?->unit,
                'normalized' => ProductLabel::normalize($medicine->catalogItem?->name),
            ])
            ->filter(fn (array $medicine) => $medicine['normalized'] !== '')
            ->values()
            ->all();

        // Un mot partagé, au moins. Sans cet index, chaque ligne du catalogue
        // fournisseur serait confrontée à tout le catalogue de la clinique.
        $byWord = [];

        foreach ($catalogue as $index => $medicine) {
            foreach (array_unique(explode(' ', $medicine['normalized'])) as $word) {
                $byWord[$word][] = $index;
            }
        }

        $found = [];

        foreach ($unlinked as $group => $row) {
            $candidates = [];

            foreach (array_unique(explode(' ', ProductLabel::normalize($row['name']))) as $word) {
                foreach ($byWord[$word] ?? [] as $index) {
                    $candidates[$index] = true;
                }
            }

            $matches = [];

            foreach (array_keys($candidates) as $index) {
                if (count($matches) >= self::MAX_SUGGESTIONS) {
                    break;
                }

                if (ProductLabel::looksLikeSameProduct($catalogue[$index]['name'], $row['name'])) {
                    $matches[] = [
                        'medicine_uuid' => $catalogue[$index]['medicine_uuid'],
                        'name' => $catalogue[$index]['name'],
                        'code' => $catalogue[$index]['code'],
                        'unit' => $catalogue[$index]['unit'],
                    ];
                }
            }

            if ($matches !== []) {
                $found[$group] = $matches;
            }
        }

        return $found;
    }

    /** @var array<int, array{0: MedicineSupplier, 1: SupplierCatalogItem}>|null */
    private ?array $unlinked = null;

    /**
     * @param  Collection<int, MedicineSupplier>  $suppliers
     * @return array<int, array{0: MedicineSupplier, 1: SupplierCatalogItem}>
     */
    private function unlinkedCatalogLines(Collection $suppliers): array
    {
        return $this->unlinked ??= SupplierCatalogItem::query()
            ->whereNull('linked_medicine_id')
            ->whereHas('catalog', fn ($query) => $query
                ->where('active_key', 'ACTIVE')
                ->whereIn('medicine_supplier_id', $suppliers->pluck('id')))
            ->with('catalog:id,uuid,medicine_supplier_id')
            ->orderBy('row_number')
            ->get()
            ->map(fn (SupplierCatalogItem $item) => [$suppliers->firstWhere('id', $item->catalog->medicine_supplier_id), $item])
            ->all();
    }
}
