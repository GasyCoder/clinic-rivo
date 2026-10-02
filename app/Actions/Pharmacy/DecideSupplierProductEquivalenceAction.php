<?php

namespace App\Actions\Pharmacy;

use App\Enums\SupplierEquivalenceStatus;
use App\Models\Medicine;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierProductEquivalence;
use App\Services\Catalog\CatalogActor;
use App\Services\Pharmacy\SupplierProductEquivalences;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-241 — dire de deux produits fournisseurs qu'ils sont le même, ou deux.
 *
 * « Le même » réunit leurs prix sur une ligne du comparateur, et rien n'est
 * créé au catalogue de la clinique. « Deux produits » les sépare, même quand
 * la règle les avait réunis, et fait taire la proposition. Une décision
 * remplace la précédente sur la même paire ; elle se réécrit, elle ne
 * s'accumule pas.
 *
 * Face à un produit de la clinique, seul « deux produits » s'écrit ici : dire
 * « le même » est le rattachement (ADR-181), qui crée un prix d'achat.
 */
class DecideSupplierProductEquivalenceAction
{
    public const PERMISSION = 'supplier_equivalences.manage';

    public function __construct(private readonly SupplierProductEquivalences $equivalences) {}

    /**
     * @param  Collection<int, SupplierCatalogItem>  $others
     * @param  Collection<int, Medicine>  $medicines
     * @return int le nombre de paires écrites
     */
    public function execute(
        SupplierCatalogItem $item,
        SupplierEquivalenceStatus $status,
        Collection $others,
        Collection $medicines,
        ?string $reason,
        CatalogActor $actor,
    ): int {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Dire si deux produits sont le même demande le droit « '.self::PERMISSION.' ».');
        }

        if ($status === SupplierEquivalenceStatus::Proposed) {
            throw ValidationException::withMessages(['status' => 'Une décision dit « le même » ou « deux produits ».']);
        }

        if ($status === SupplierEquivalenceStatus::Same && $medicines->isNotEmpty()) {
            throw ValidationException::withMessages([
                'medicine_uuids' => 'Pour dire qu’une ligne est un produit de la clinique, rattachez-la : c’est ce qui crée son prix d’achat.',
            ]);
        }

        if ($others->isEmpty() && $medicines->isEmpty()) {
            throw ValidationException::withMessages(['other_item_uuids' => 'Indiquez à quel produit la comparer.']);
        }

        $item->loadMissing('catalog:id,medicine_supplier_id');
        $node = SupplierProductEquivalences::nodeOf($item, (int) $item->catalog->medicine_supplier_id);

        $written = DB::transaction(function () use ($item, $node, $status, $others, $medicines, $reason, $actor): int {
            $count = 0;

            foreach ($others as $other) {
                $other->loadMissing('catalog:id,medicine_supplier_id');
                $otherNode = SupplierProductEquivalences::nodeOf($other, (int) $other->catalog->medicine_supplier_id);

                if ($otherNode === $node) {
                    // La même référence chez le même fournisseur : c'est déjà un seul produit.
                    continue;
                }

                $this->writePair($item, $node, $other, $otherNode, $status, $reason, $actor, SupplierProductEquivalence::SOURCE_HUMAN);
                $count++;
            }

            foreach ($medicines as $medicine) {
                SupplierProductEquivalence::query()->updateOrCreate(
                    [
                        'medicine_supplier_id' => (int) $item->catalog->medicine_supplier_id,
                        'product_ref' => SupplierProductEquivalences::refOf($item),
                        'medicine_id' => $medicine->getKey(),
                    ],
                    [
                        'label' => mb_substr((string) $item->medicine_label, 0, 255),
                        ...$this->decision($status, SupplierProductEquivalence::SOURCE_HUMAN, $reason, $actor),
                    ],
                );
                $count++;
            }

            return $count;
        });

        $this->equivalences->forget();

        return $written;
    }

    /**
     * Les propositions de l'IA : écrites « à décider », jamais par-dessus
     * une décision humaine.
     *
     * @param  array<int, array{0: SupplierCatalogItem, 1: SupplierCatalogItem, 2: ?string}>  $pairs
     */
    public function propose(array $pairs, CatalogActor $actor): int
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Rapprocher par l’IA demande le droit « '.self::PERMISSION.' ».');
        }

        $count = DB::transaction(function () use ($pairs, $actor): int {
            $count = 0;

            foreach ($pairs as [$item, $other, $note]) {
                $item->loadMissing('catalog:id,medicine_supplier_id');
                $other->loadMissing('catalog:id,medicine_supplier_id');
                $node = SupplierProductEquivalences::nodeOf($item, (int) $item->catalog->medicine_supplier_id);
                $otherNode = SupplierProductEquivalences::nodeOf($other, (int) $other->catalog->medicine_supplier_id);

                if ($node === $otherNode || $this->existing($node, $otherNode)) {
                    continue;
                }

                $this->writePair($item, $node, $other, $otherNode, SupplierEquivalenceStatus::Proposed, $note, $actor, SupplierProductEquivalence::SOURCE_AI);
                $count++;
            }

            return $count;
        });

        $this->equivalences->forget();

        return $count;
    }

    /** Oublier une décision : la paire redevient ce que la règle en dit. */
    public function forget(SupplierProductEquivalence $equivalence, CatalogActor $actor): void
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Oublier une décision demande le droit « '.self::PERMISSION.' ».');
        }

        $equivalence->delete();
        $this->equivalences->forget();
    }

    private function writePair(
        SupplierCatalogItem $item,
        string $node,
        SupplierCatalogItem $other,
        string $otherNode,
        SupplierEquivalenceStatus $status,
        ?string $reason,
        CatalogActor $actor,
        string $source,
    ): void {
        [$first] = SupplierProductEquivalences::ordered($node, $otherNode);
        [$a, $aNode, $b, $bNode] = $first === $node ? [$item, $node, $other, $otherNode] : [$other, $otherNode, $item, $node];

        SupplierProductEquivalence::query()->updateOrCreate(
            [
                'medicine_supplier_id' => (int) $a->catalog->medicine_supplier_id,
                'product_ref' => substr($aNode, strpos($aNode, '|') + 1),
                'other_medicine_supplier_id' => (int) $b->catalog->medicine_supplier_id,
                'other_product_ref' => substr($bNode, strpos($bNode, '|') + 1),
            ],
            [
                'label' => mb_substr((string) $a->medicine_label, 0, 255),
                'other_label' => mb_substr((string) $b->medicine_label, 0, 255),
                'medicine_id' => null,
                ...$this->decision($status, $source, $reason, $actor),
            ],
        );
    }

    private function existing(string $node, string $otherNode): bool
    {
        [$a, $b] = SupplierProductEquivalences::ordered($node, $otherNode);

        return SupplierProductEquivalence::query()
            ->where('medicine_supplier_id', (int) strstr($a, '|', true))
            ->where('product_ref', substr($a, strpos($a, '|') + 1))
            ->where('other_medicine_supplier_id', (int) strstr($b, '|', true))
            ->where('other_product_ref', substr($b, strpos($b, '|') + 1))
            ->exists();
    }

    /** @return array<string, mixed> */
    private function decision(SupplierEquivalenceStatus $status, string $source, ?string $reason, CatalogActor $actor): array
    {
        $reason = trim((string) $reason);

        return [
            'status' => $status,
            'source' => $source,
            'reason' => $reason === '' ? null : mb_substr($reason, 0, 1000),
            'decided_by' => $actor->localUserId(),
            ...$actor->externalAttribution('decided'),
            'decided_at' => $status === SupplierEquivalenceStatus::Proposed ? null : now(),
        ];
    }
}
