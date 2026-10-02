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
    public function __construct(private readonly SupplierProductEquivalences $equivalences) {}

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

        // ADR-241 — chaque produit est un « nœud » : un produit de la clinique
        // (« m:{id} ») ou un produit d'un fournisseur (« {fournisseur}|{réf} »).
        // Les nœuds qui désignent le même produit forment une ligne.
        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = [];

        foreach ($offers->groupBy('medicine_id') as $group) {
            /** @var Medicine $medicine */
            $medicine = $group->first()->medicine;

            $nodes['m:'.$medicine->id] = [
                'medicine' => [
                    'key' => 'medicine:'.$medicine->uuid,
                    'medicine_uuid' => $medicine->uuid,
                    'medicine_id' => $medicine->id,
                    'code' => $medicine->catalogItem?->code,
                    'name' => $medicine->catalogItem?->name,
                    'unit' => $medicine->catalogItem?->unit,
                    // La famille que la clinique a donnée à ce produit.
                    'family' => $medicine->category?->name,
                    'in_stock' => $medicine->lots
                        ->filter(fn (MedicineLot $lot) => $lot->isUsableOn($today))
                        ->sum(fn (MedicineLot $lot) => $lot->availableQuantity()),
                    'minimum_stock' => $medicine->minimum_stock,
                ],
                'label' => (string) $medicine->catalogItem?->name,
                'items' => [],
                'quotes' => $group->map(fn (MedicineSupplierOffer $offer): array => [
                    'key' => 'offer:'.$offer->id,
                    'supplier_uuid' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->uuid,
                    'supplier_name' => $selected->firstWhere('id', $offer->medicine_supplier_id)?->name,
                    'supplier_catalog_item_uuid' => null,
                    'supplier_catalog_uuid' => null,
                    'reference' => null,
                    'label' => null,
                    'can_link' => false,
                    'price' => Money::normalize((string) $offer->quoted_price),
                    'effective_from' => $offer->effective_from?->toDateString(),
                ])->values()->all(),
            ];
        }

        // The supplier's active catalogue lines the clinic has not taken up
        // yet. Without them, a supplier whose catalogue is imported but not
        // yet linked offered nothing here, although the order form of its
        // own folder lists every line.
        $clinicFamilies = $this->clinicFamilies();

        foreach ($this->unlinkedCatalogLines($selected) as [$supplier, $item]) {
            $node = SupplierProductEquivalences::nodeOf($item, $supplier->id);
            $nodes[$node] ??= [
                'medicine' => null,
                'label' => (string) $item->medicine_label,
                'code' => $item->reference,
                'unit' => $item->presentation,
                // La famille que le fournisseur déclare, écrite comme celle de
                // la clinique quand elles se ressemblent (ADR-098).
                'family' => $this->familyOf($item->family_label, $clinicFamilies),
                'items' => [],
                'quotes' => [],
            ];

            $nodes[$node]['items'][] = $item->uuid;
            $nodes[$node]['quotes'][] = [
                'key' => 'catalog-item:'.$item->uuid,
                'supplier_uuid' => $supplier->uuid,
                'supplier_name' => $supplier->name,
                'supplier_catalog_item_uuid' => $item->uuid,
                // Le rattachement se fait sur la ligne, dans son fichier.
                'supplier_catalog_uuid' => $item->catalog->uuid,
                'reference' => $item->reference,
                'label' => (string) $item->medicine_label,
                'price' => filled($item->supplier_price) ? Money::normalize((string) $item->supplier_price) : null,
                // Rattacher crée le prix d'achat : sans prix, il n'y a rien à
                // créer, et l'action le refuse (ADR-098).
                'can_link' => filled($item->supplier_price),
                'effective_from' => null,
            ];
        }

        $roots = $this->group($nodes);
        $rows = $this->rows($nodes, $roots);
        $suggestions = $this->reconciliations($rows, $nodes);
        $peers = $this->peers($rows, $nodes, $roots);

        $medicines = collect($rows)
            ->map(function (array $row, string $root) use ($suggestions, $peers): array {
                // Cheapest first, so the comparison reads itself; the
                // choice stays the buyer's — a cheaper supplier may be out
                // of stock or slower, and the screen never decides. A line
                // without a price comes last: it cannot be compared.
                $quotes = collect($row['quotes'])
                    ->sortBy(fn (array $quote) => $quote['price'] === null ? PHP_FLOAT_MAX : (float) $quote['price'])
                    ->values();

                unset($row['nodes']);

                return [
                    ...$row,
                    'best_price' => $quotes->first(fn (array $quote) => $quote['price'] !== null)['price'] ?? null,
                    'quotes' => $quotes->all(),
                    // ADR-181 — « c'est peut-être le produit que la clinique
                    // tient déjà sous un autre nom ». Jamais une décision.
                    'suggestions' => $suggestions[$root] ?? [],
                    // ADR-241 — « c'est peut-être le même produit qu'un autre
                    // fournisseur nomme autrement », par la règle ou l'IA.
                    'peers' => $peers[$root] ?? [],
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
            // Combien de lignes ressemblent à un produit déjà tenu par la
            // clinique, ou à celui d'un autre fournisseur : sans ce compte,
            // personne ne sait qu'il y a des prix à rapprocher.
            'to_reconcile' => collect($medicines)->filter(fn (array $row) => $row['suggestions'] !== [] || $row['peers'] !== [])->count(),
            // Une proposition s'affiche sur ses deux lignes : on la compte une fois.
            'proposed_by_ai' => collect($medicines)->flatMap(fn (array $row) => collect($row['peers'])->where('source', 'AI')->pluck('equivalence_uuid'))->unique()->count(),
        ];
    }

    /**
     * ADR-241 — les nœuds qui désignent le même produit : même clé canonique
     * (abréviations, unités, ordre des mots), ou un humain qui l'a dit. Un
     * « deux produits » décidé sépare toujours ; deux produits de la clinique
     * ne se fondent jamais en une ligne.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, string> nœud => racine
     */
    private function group(array $nodes): array
    {
        $parent = array_combine(array_keys($nodes), array_keys($nodes));
        $medicineOf = [];

        foreach ($nodes as $id => $node) {
            if ($node['medicine'] !== null) {
                $medicineOf[$id] = $node['medicine']['medicine_id'];
            }
        }

        $find = function (string $node) use (&$parent): string {
            while ($parent[$node] !== $node) {
                $parent[$node] = $parent[$parent[$node]];
                $node = $parent[$node];
            }

            return $node;
        };

        $differentFromMedicine = $this->equivalences->differentFromMedicine();
        $union = function (string $first, string $second) use (&$parent, &$medicineOf, $find, $differentFromMedicine): void {
            $a = $find($first);
            $b = $find($second);

            if ($a === $b || (isset($medicineOf[$a]) && isset($medicineOf[$b]))) {
                return;
            }

            foreach ([[$a, $b], [$b, $a]] as [$catalogRoot, $medicineRoot]) {
                if (isset($medicineOf[$medicineRoot]) && isset($differentFromMedicine[$catalogRoot.'#'.$medicineOf[$medicineRoot]])) {
                    return;
                }
            }

            $parent[$b] = $a;
            $medicineOf[$a] ??= $medicineOf[$b] ?? null;

            if ($medicineOf[$a] === null) {
                unset($medicineOf[$a]);
            }
        };

        // 1. La règle : même clé canonique, sauf « deux produits » décidé.
        $byKey = [];

        foreach ($nodes as $id => $node) {
            $key = ProductLabel::key($node['label']);

            if ($key !== '') {
                $byKey[$key][] = $id;
            }
        }

        foreach ($byKey as $members) {
            foreach ($members as $index => $first) {
                foreach (array_slice($members, $index + 1) as $second) {
                    if (! $this->equivalences->isDifferent($first, $second)) {
                        $union($first, $second);
                    }
                }
            }
        }

        // 2. Les décisions humaines « le même produit ». Une ligne déjà
        // rattachée n'est plus au comparateur : elle y est son médicament.
        $alias = $this->linkedNodes();
        $present = fn (string $node): ?string => isset($parent[$node]) ? $node : (isset($alias[$node], $parent[$alias[$node]]) ? $alias[$node] : null);

        foreach ($this->equivalences->sameEdges() as $first => $others) {
            foreach ($others as $second) {
                $a = $present($first);
                $b = $present($second);

                if ($a !== null && $b !== null) {
                    $union($a, $b);
                }
            }
        }

        $roots = [];

        foreach (array_keys($nodes) as $id) {
            $roots[$id] = $find($id);
        }

        return $roots;
    }

    /**
     * Les lignes de catalogue rattachées à un produit de la clinique, par
     * nœud : une décision « le même » qui les nomme désigne ce produit.
     *
     * @return array<string, string> nœud => « m:{médicament} »
     */
    private function linkedNodes(): array
    {
        $wanted = $this->equivalences->sameEdges();

        if ($wanted === []) {
            return [];
        }

        $suppliers = array_unique(array_map(fn (string $node) => (int) strstr($node, '|', true), array_keys($wanted)));

        return SupplierCatalogItem::query()
            ->whereNotNull('linked_medicine_id')
            ->whereHas('catalog', fn ($query) => $query->whereIn('medicine_supplier_id', $suppliers))
            ->with('catalog:id,medicine_supplier_id')
            ->get()
            ->mapWithKeys(fn (SupplierCatalogItem $item) => [
                SupplierProductEquivalences::nodeOf($item, (int) $item->catalog->medicine_supplier_id) => 'm:'.$item->linked_medicine_id,
            ])
            ->filter(fn (string $medicine, string $node) => isset($wanted[$node]))
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, string>  $roots
     * @return array<string, array<string, mixed>> racine => ligne
     */
    private function rows(array $nodes, array $roots): array
    {
        $members = [];

        foreach ($roots as $node => $root) {
            $members[$root][] = $node;
        }

        $rows = [];

        foreach ($members as $root => $ids) {
            // Le produit de la clinique d'abord ; sinon le libellé le plus
            // court, qui est souvent le plus lisible.
            usort($ids, fn (string $a, string $b) => [$nodes[$a]['medicine'] === null, mb_strlen($nodes[$a]['label']), $nodes[$a]['label']]
                <=> [$nodes[$b]['medicine'] === null, mb_strlen($nodes[$b]['label']), $nodes[$b]['label']]);

            $first = $nodes[$ids[0]];
            $medicine = $first['medicine'];
            $name = $medicine['name'] ?? $first['label'];
            $nameKey = ProductLabel::normalize($name);

            $quotes = [];

            foreach ($ids as $id) {
                foreach ($nodes[$id]['quotes'] as $quote) {
                    // Le libellé du fournisseur n'est montré que s'il diffère
                    // de celui de la ligne : c'est lui qu'on relit avant de
                    // séparer.
                    $quote['label'] = $quote['label'] !== null && ProductLabel::normalize($quote['label']) !== $nameKey ? $quote['label'] : null;
                    $quotes[] = $quote;
                }
            }

            $rows[$root] = [
                'key' => $medicine['key'] ?? 'catalog:'.ProductLabel::key($name).':'.$ids[0],
                'medicine_uuid' => $medicine['medicine_uuid'] ?? null,
                'code' => $medicine['code'] ?? $first['code'],
                'name' => $name,
                'unit' => $medicine['unit'] ?? $first['unit'],
                'family' => $medicine['family'] ?? collect($ids)->map(fn (string $id) => $nodes[$id]['family'] ?? null)->filter()->first(),
                'in_clinic_catalog' => $medicine !== null,
                'product_group' => ProductLabel::key($name),
                'in_stock' => $medicine['in_stock'] ?? null,
                'minimum_stock' => $medicine['minimum_stock'] ?? null,
                // ADR-241 — les lignes de catalogue réunies sur cette ligne,
                // pour qu'on puisse en séparer une.
                'members' => [
                    'item_uuids' => collect($ids)->map(fn (string $id) => $nodes[$id]['items'][0] ?? null)->filter()->values()->all(),
                    'medicine_uuid' => $medicine['medicine_uuid'] ?? null,
                ],
                'nodes' => $ids,
                'quotes' => $quotes,
            ];
        }

        return $rows;
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

    /** Au-delà, un mot est trop commun pour trouver des candidats. */
    private const MAX_CANDIDATES = 300;

    /**
     * Les lignes de catalogue fournisseur qui désignent peut-être un produit
     * déjà au catalogue de la clinique, écrit autrement.
     *
     * Sans rapprochement, ces deux libellés restent deux lignes : leurs prix
     * ne se comparent pas, et commander la ligne du fournisseur créerait un
     * second produit, avec son propre stock. Le rapprochement est donc le seul
     * moyen de comparer — mais il reste un geste humain : la règle propose,
     * l'acheteur confirme en lisant les deux libellés. Un « deux produits »
     * déjà dit fait taire la proposition (ADR-241).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $nodes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function reconciliations(array $rows, array $nodes): array
    {
        $unlinked = array_filter($rows, fn (array $row) => $row['in_clinic_catalog'] === false);

        if ($unlinked === []) {
            return [];
        }

        $catalogue = Medicine::query()
            ->where('active', true)
            ->with('catalogItem:id,code,name,unit')
            ->get()
            ->map(fn (Medicine $medicine): array => [
                'id' => $medicine->id,
                'medicine_uuid' => $medicine->uuid,
                'name' => $medicine->catalogItem?->name,
                'code' => $medicine->catalogItem?->code,
                'unit' => $medicine->catalogItem?->unit,
                'words' => ProductLabel::words($medicine->catalogItem?->name),
            ])
            ->filter(fn (array $medicine) => $medicine['words'] !== [])
            ->values()
            ->all();

        $index = $this->wordIndex(array_column($catalogue, 'words'));
        $refused = $this->equivalences->differentFromMedicine();
        $found = [];

        foreach ($unlinked as $root => $row) {
            $matches = [];

            foreach ($this->candidates(ProductLabel::words($row['name']), $index) as $position) {
                if (count($matches) >= self::MAX_SUGGESTIONS) {
                    break;
                }

                $medicine = $catalogue[$position];

                if (collect($row['nodes'])->contains(fn (string $node) => isset($refused[$node.'#'.$medicine['id']]))) {
                    continue;
                }

                if (ProductLabel::looksLikeSameProduct($medicine['name'], $row['name'])) {
                    $matches[] = [
                        'medicine_uuid' => $medicine['medicine_uuid'],
                        'name' => $medicine['name'],
                        'code' => $medicine['code'],
                        'unit' => $medicine['unit'],
                    ];
                }
            }

            if ($matches !== []) {
                $found[$root] = $matches;
            }
        }

        return $found;
    }

    /**
     * ADR-241 — deux fournisseurs qui nomment peut-être le même produit
     * autrement, et qui restent deux lignes : la règle le propose (« paracétamol
     * cp » face à « comprimé paracétamol 500 mg »), l'IA aussi. Un humain
     * décide ; « deux produits » fait taire la proposition.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, string>  $roots
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function peers(array $rows, array $nodes, array $roots): array
    {
        $open = array_filter($rows, fn (array $row) => $row['in_clinic_catalog'] === false && $row['members']['item_uuids'] !== []);

        if (count($open) < 2) {
            return [];
        }

        $keys = array_keys($open);
        $words = array_map(fn (string $root) => ProductLabel::words($open[$root]['name']), $keys);
        $index = $this->wordIndex($words);
        $found = [];
        $suppliersOf = array_map(fn (array $row) => collect($row['quotes'])->pluck('supplier_uuid')->filter()->unique()->values()->all(), $open);

        $describe = fn (string $root, string $source, array $extra = []): array => [
            'row_key' => $rows[$root]['key'],
            'name' => $rows[$root]['name'],
            'code' => $rows[$root]['code'],
            'unit' => $rows[$root]['unit'],
            'item_uuid' => $rows[$root]['members']['item_uuids'][0],
            'suppliers' => collect($rows[$root]['quotes'])->pluck('supplier_name')->unique()->values()->all(),
            'best_price' => collect($rows[$root]['quotes'])->pluck('price')->filter()->sortBy(fn ($price) => (float) $price)->first(),
            'source' => $source,
            ...$extra,
        ];

        $add = function (string $first, string $second, string $source, array $extra = []) use (&$found, $describe): void {
            foreach ([[$first, $second], [$second, $first]] as [$from, $to]) {
                if (collect($found[$from] ?? [])->contains('row_key', $describe($to, $source)['row_key'])) {
                    continue;
                }

                if (count($found[$from] ?? []) < self::MAX_SUGGESTIONS || $source === 'AI') {
                    $found[$from][] = $describe($to, $source, $extra);
                }
            }
        };

        $refused = fn (string $first, string $second): bool => collect($rows[$first]['nodes'])->contains(
            fn (string $a) => collect($rows[$second]['nodes'])->contains(fn (string $b) => $this->equivalences->isDifferent($a, $b)),
        );

        // Ce que l'IA a proposé d'abord : un humain l'attend.
        foreach ($this->equivalences->proposals() as $proposal) {
            $first = $roots[SupplierProductEquivalences::node($proposal->medicine_supplier_id, $proposal->product_ref)] ?? null;
            $second = $roots[SupplierProductEquivalences::node((int) $proposal->other_medicine_supplier_id, $proposal->other_product_ref)] ?? null;

            if ($first !== null && $second !== null && $first !== $second && isset($open[$first], $open[$second])) {
                $add($first, $second, 'AI', ['equivalence_uuid' => $proposal->uuid, 'reason' => $proposal->reason]);
            }
        }

        foreach ($keys as $position => $root) {
            foreach ($this->candidates($words[$position], $index) as $other) {
                if ($other === $position) {
                    continue;
                }

                $otherRoot = $keys[$other];

                // Deux lignes du même fournisseur ne se comparent pas : c'est
                // entre fournisseurs que le prix se joue (ADR-098 traite déjà
                // le même produit sous deux références d'un même catalogue).
                if ($suppliersOf[$root] !== [] && array_intersect($suppliersOf[$root], $suppliersOf[$otherRoot]) !== []) {
                    continue;
                }

                if (ProductLabel::looksLikeSameProduct($open[$root]['name'], $open[$otherRoot]['name']) && ! $refused($root, $otherRoot)) {
                    $add($root, $otherRoot, 'RULE');
                }
            }
        }

        return $found;
    }

    /**
     * Chaque mot, les positions qui le portent ; et, pour chaque position, le
     * plus rare de ses mots. Une règle « l'un dit tout ce que dit l'autre »
     * n'a besoin que de ces deux index : si A est contenu dans B, B porte le
     * mot le plus rare de A.
     *
     * @param  array<int, array<int, string>>  $wordLists
     * @return array{words: array<string, array<int, int>>, rarest: array<string, array<int, int>>}
     */
    private function wordIndex(array $wordLists): array
    {
        $words = [];

        foreach ($wordLists as $position => $list) {
            foreach ($list as $word) {
                $words[$word][] = $position;
            }
        }

        $rarest = [];

        foreach ($wordLists as $position => $list) {
            $best = $this->rarestOf($list, $words);

            if ($best !== null) {
                $rarest[$best][] = $position;
            }
        }

        return ['words' => $words, 'rarest' => $rarest];
    }

    /**
     * @param  array<int, string>  $words
     * @param  array{words: array<string, array<int, int>>, rarest: array<string, array<int, int>>}  $index
     * @return array<int, int>
     */
    private function candidates(array $words, array $index): array
    {
        $found = [];
        $best = $this->rarestOf($words, $index['words']);

        // Ceux qui contiennent tout ce que dit ce libellé…
        foreach (array_slice($best === null ? [] : $index['words'][$best], 0, self::MAX_CANDIDATES) as $position) {
            $found[$position] = true;
        }

        // … et ceux dont tout ce qu'ils disent est dans ce libellé.
        foreach ($words as $word) {
            foreach (array_slice($index['rarest'][$word] ?? [], 0, self::MAX_CANDIDATES) as $position) {
                $found[$position] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * @param  array<int, string>  $words
     * @param  array<string, array<int, int>>  $byWord
     */
    private function rarestOf(array $words, array $byWord): ?string
    {
        $best = null;

        foreach ($words as $word) {
            if (isset($byWord[$word]) && ($best === null || count($byWord[$word]) < count($byWord[$best]))) {
                $best = $word;
            }
        }

        return $best;
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
