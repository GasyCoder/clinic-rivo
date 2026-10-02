<?php

namespace App\Services\Pharmacy;

use App\Enums\SupplierEquivalenceStatus;
use App\Models\Medicine;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierProductEquivalence;
use App\Support\ProductLabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ADR-241 — ce que les humains (et l'IA) ont dit des paires de produits
 * fournisseurs, lu une fois.
 *
 * Un produit fournisseur est un « nœud » : `{fournisseur}|{référence}`. La
 * référence est celle du catalogue ; sans référence, le libellé normalisé —
 * relire un catalogue recrée ses lignes, la décision doit leur survivre.
 */
class SupplierProductEquivalences
{
    /** @var Collection<int, SupplierProductEquivalence>|null */
    private ?Collection $rows = null;

    /** @var array<string, array<int, string>>|null nœud => nœuds « même produit » */
    private ?array $sameEdges = null;

    public static function refOf(SupplierCatalogItem $item): string
    {
        $reference = Str::of((string) $item->reference)->squish()->upper()->toString();

        return $reference !== ''
            ? 'ref:'.mb_substr($reference, 0, 180)
            : 'label:'.mb_substr(ProductLabel::normalize($item->medicine_label), 0, 180);
    }

    public static function node(int $supplierId, string $ref): string
    {
        return $supplierId.'|'.$ref;
    }

    public static function nodeOf(SupplierCatalogItem $item, int $supplierId): string
    {
        return self::node($supplierId, self::refOf($item));
    }

    /**
     * Une paire, toujours dans le même ordre : (a, b) et (b, a) sont la même
     * ligne de la table.
     *
     * @return array{0: string, 1: string}
     */
    public static function ordered(string $first, string $second): array
    {
        return strcmp($first, $second) <= 0 ? [$first, $second] : [$second, $first];
    }

    /** @return Collection<int, SupplierProductEquivalence> */
    public function all(): Collection
    {
        return $this->rows ??= SupplierProductEquivalence::query()->get();
    }

    public function forget(): void
    {
        $this->rows = null;
        $this->sameEdges = null;
    }

    /** @return array<string, array<int, string>> */
    public function sameEdges(): array
    {
        if ($this->sameEdges !== null) {
            return $this->sameEdges;
        }

        $edges = [];

        foreach ($this->pairs(SupplierEquivalenceStatus::Same) as [$first, $second]) {
            $edges[$first][] = $second;
            $edges[$second][] = $first;
        }

        return $this->sameEdges = $edges;
    }

    /** @return array<string, true> clé de paire => vrai */
    public function differentPairs(): array
    {
        $pairs = [];

        foreach ($this->pairs(SupplierEquivalenceStatus::Different) as [$first, $second]) {
            $pairs[$first."\n".$second] = true;
        }

        return $pairs;
    }

    public function isDifferent(string $first, string $second): bool
    {
        [$a, $b] = self::ordered($first, $second);

        return isset($this->differentPairs()[$a."\n".$b]);
    }

    /** @return array<string, true> « nœud#médicament » refusés */
    public function differentFromMedicine(): array
    {
        return $this->all()
            ->filter(fn (SupplierProductEquivalence $row) => $row->medicine_id !== null && $row->status === SupplierEquivalenceStatus::Different)
            ->mapWithKeys(fn (SupplierProductEquivalence $row) => [self::node($row->medicine_supplier_id, $row->product_ref).'#'.$row->medicine_id => true])
            ->all();
    }

    /** @return Collection<int, SupplierProductEquivalence> */
    public function proposals(): Collection
    {
        return $this->all()->filter(fn (SupplierProductEquivalence $row) => $row->status === SupplierEquivalenceStatus::Proposed && $row->other_product_ref !== null)->values();
    }

    /**
     * Les nœuds reliés à celui-ci par des « même produit », lui compris.
     *
     * @return array<int, string>
     */
    public function component(string $node): array
    {
        $edges = $this->sameEdges();
        $seen = [$node => true];
        $queue = [$node];

        while ($queue !== []) {
            foreach ($edges[array_shift($queue)] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Le produit de la clinique qu'un humain a déjà dit être celui-ci, par
     * une autre ligne qui lui est rattachée. Commander la ligne réutilise
     * alors ce produit, au lieu d'en créer un second (ADR-098).
     */
    public function linkedMedicineFor(SupplierCatalogItem $item): ?Medicine
    {
        $item->loadMissing('catalog:id,medicine_supplier_id');
        $node = self::nodeOf($item, (int) $item->catalog->medicine_supplier_id);
        $others = array_values(array_diff($this->component($node), [$node]));

        if ($others === []) {
            return null;
        }

        $wanted = array_flip($others);
        $suppliers = array_unique(array_map(fn (string $other) => (int) Str::before($other, '|'), $others));

        $linked = SupplierCatalogItem::query()
            ->whereNotNull('linked_medicine_id')
            ->whereHas('catalog', fn ($query) => $query->withTrashed()->whereIn('medicine_supplier_id', $suppliers))
            ->with('catalog:id,medicine_supplier_id')
            ->get()
            ->first(fn (SupplierCatalogItem $candidate) => isset($wanted[self::nodeOf($candidate, (int) $candidate->catalog->medicine_supplier_id)]));

        return $linked ? Medicine::query()->find($linked->linked_medicine_id) : null;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function pairs(SupplierEquivalenceStatus $status): array
    {
        return $this->all()
            ->filter(fn (SupplierProductEquivalence $row) => $row->status === $status && $row->other_product_ref !== null)
            ->map(fn (SupplierProductEquivalence $row) => self::ordered(
                self::node($row->medicine_supplier_id, $row->product_ref),
                self::node((int) $row->other_medicine_supplier_id, $row->other_product_ref),
            ))
            ->values()
            ->all();
    }
}
