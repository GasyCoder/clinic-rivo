<?php

namespace App\Services\Laboratory;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisCatalogManager
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User|CatalogActor $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($data, $actor): AnalysisCatalog {
            $catalogActor = $this->actor($actor);
            [$catalogItem, $parent] = $this->relations($data);

            return AnalysisCatalog::query()->create([
                ...Arr::except($data, ['catalog_item_uuid', 'parent_uuid']),
                'catalog_item_id' => $catalogItem->getKey(),
                'parent_id' => $parent?->getKey(),
                'created_by' => $catalogActor->localUserId(),
                'updated_by' => $catalogActor->localUserId(),
                ...$catalogActor->externalAttribution('created'),
                ...$catalogActor->externalAttribution('updated'),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(AnalysisCatalog $analysis, array $data, User|CatalogActor $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($analysis, $data, $actor): AnalysisCatalog {
            $catalogActor = $this->actor($actor);
            $locked = AnalysisCatalog::query()->lockForUpdate()->findOrFail($analysis->getKey());
            [$catalogItem, $parent] = $this->relations($data, $locked);

            $locked->update([
                ...Arr::except($data, ['catalog_item_uuid', 'parent_uuid']),
                'catalog_item_id' => $catalogItem->getKey(),
                'parent_id' => $parent?->getKey(),
                'updated_by' => $catalogActor->localUserId(),
                ...$catalogActor->externalAttribution('updated'),
            ]);

            if ($locked->wasChanged('catalog_item_id')) {
                $this->synchronizeDescendantCatalogItem($locked, $catalogItem, $catalogActor);
            }

            return $locked->fresh(['catalogItem', 'parent']);
        });
    }

    /**
     * Saves a group (or standalone analysis) together with its inline
     * sub-analyses in one transaction — additive to create()/update(),
     * which stay the path for a sub-analysis that itself needs its own
     * sub-analyses (depth > 1), via the normal parent picker.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveWithChildren(?AnalysisCatalog $existing, array $data, User|CatalogActor $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($existing, $data, $actor): AnalysisCatalog {
            $children = $data['children'] ?? [];
            $rootData = Arr::except($data, ['children']);

            $root = $existing
                ? $this->update($existing, $rootData, $actor)
                : $this->create($rootData, $actor);

            $this->syncChildren($root, $children, $actor);

            return $root->fresh(['catalogItem', 'parent', 'children']);
        });
    }

    /**
     * Recursive on purpose: a sub-analysis edited inline can itself be a
     * group with its own sub-analyses (grandchildren from $parent's point of
     * view). The form only ever sends two levels deep (validated in
     * StoreAnalysisCatalogRequest/the API rules), but nothing here assumes
     * that depth — a request simply won't carry a third level to recurse
     * into, since Laravel drops any input key without a matching rule.
     *
     * @param  array<int, array<string, mixed>>  $children
     */
    private function syncChildren(AnalysisCatalog $parent, array $children, User|CatalogActor $actor): void
    {
        $keptIds = [];

        foreach (array_values($children) as $index => $childData) {
            $childUuid = $childData['uuid'] ?? null;
            $existingChild = $childUuid
                ? AnalysisCatalog::query()->where('uuid', $childUuid)->where('parent_id', $parent->getKey())->first()
                : null;

            $codeOwner = AnalysisCatalog::withTrashed()->where('code', $childData['code'])->first();
            if ($codeOwner && (! $existingChild || $codeOwner->isNot($existingChild))) {
                throw ValidationException::withMessages([
                    "children.{$index}.code" => "Le code « {$childData['code']} » est déjà utilisé.",
                ]);
            }

            $grandchildren = $childData['children'] ?? [];
            $payload = [
                ...Arr::except($childData, ['uuid', 'children']),
                'catalog_item_uuid' => $parent->catalogItem->uuid,
                'parent_uuid' => $parent->uuid,
                'display_order' => $childData['display_order'] ?? ($index + 1),
                'is_active' => $childData['is_active'] ?? true,
            ];

            $child = $existingChild
                ? $this->update($existingChild, $payload, $actor)
                : $this->create($payload, $actor);

            $keptIds[] = $child->getKey();

            if ($child->level === AnalysisCatalog::CONTAINER_LEVEL) {
                $this->syncChildren($child, $grandchildren, $actor);
            }
        }

        // A sub-analysis removed from the inline editor is deactivated,
        // never deleted — matches ADR-010: reference data is archived, not
        // destroyed.
        $parent->children()
            ->whereNotIn('id', $keptIds ?: [0])
            ->where('is_active', true)
            ->get()
            ->each(fn (AnalysisCatalog $orphan) => $this->setActive($orphan, false, $actor));
    }

    public function setActive(AnalysisCatalog $analysis, bool $active, User|CatalogActor $actor): AnalysisCatalog
    {
        $catalogActor = $this->actor($actor);
        $analysis->update([
            'is_active' => $active,
            'updated_by' => $catalogActor->localUserId(),
            ...$catalogActor->externalAttribution('updated'),
        ]);

        return $analysis->fresh();
    }

    private function actor(User|CatalogActor $actor): CatalogActor
    {
        return $actor instanceof User ? CatalogActor::fromUser($actor) : $actor;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{CatalogItem, ?AnalysisCatalog}
     */
    private function relations(array $data, ?AnalysisCatalog $current = null): array
    {
        $catalogItem = CatalogItem::query()
            ->where('uuid', $data['catalog_item_uuid'])
            ->where('type', CatalogItemType::Service->value)
            ->where('module', CatalogModule::Laboratory->value)
            ->firstOrFail();

        $parent = filled($data['parent_uuid'] ?? null)
            ? AnalysisCatalog::query()->where('uuid', $data['parent_uuid'])->firstOrFail()
            : null;

        if ($parent && $current && $parent->is($current)) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une analyse ne peut pas être son propre parent.']);
        }

        if ($parent && ! $parent->acceptsChildren()) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'Le parent sélectionné doit être un groupe d’analyses.',
            ]);
        }

        if ($parent && $current && $this->isDescendantOf($parent, $current)) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'Ce groupe appartient déjà à cette arborescence. Ce choix créerait une boucle.',
            ]);
        }

        if ($parent && $parent->catalog_item_id !== $catalogItem->getKey()) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'Le parent doit appartenir à la même prestation Laboratoire.',
            ]);
        }

        $level = $data['level'] ?? null;

        if ($level === AnalysisCatalog::TERMINAL_LEVEL && ! $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une sous-analyse doit posséder un parent.']);
        }

        if ($level === AnalysisCatalog::STANDALONE_LEVEL && $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une analyse autonome ne peut pas posséder de parent.']);
        }

        if ($current && $current->children()->exists() && $level !== AnalysisCatalog::CONTAINER_LEVEL) {
            throw ValidationException::withMessages([
                'level' => 'Ce groupe contient des éléments et doit rester de niveau Groupe.',
            ]);
        }

        return [$catalogItem, $parent];
    }

    private function isDescendantOf(AnalysisCatalog $candidate, AnalysisCatalog $ancestor): bool
    {
        $visited = [];
        $current = $candidate;

        while ($current->parent_id !== null) {
            if (isset($visited[$current->getKey()])) {
                throw ValidationException::withMessages([
                    'parent_uuid' => 'La hiérarchie existante contient déjà une relation cyclique.',
                ]);
            }

            $visited[$current->getKey()] = true;

            if ($current->parent_id === $ancestor->getKey()) {
                return true;
            }

            $current = AnalysisCatalog::query()->find($current->parent_id);
            if (! $current) {
                return false;
            }
        }

        return false;
    }

    private function synchronizeDescendantCatalogItem(
        AnalysisCatalog $group,
        CatalogItem $catalogItem,
        CatalogActor $actor,
    ): void {
        $pending = $group->children()->get();

        while ($pending->isNotEmpty()) {
            $next = collect();

            foreach ($pending as $descendant) {
                $descendant->update([
                    'catalog_item_id' => $catalogItem->getKey(),
                    'updated_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('updated'),
                ]);

                $next->push(...$descendant->children()->get());
            }

            $pending = $next;
        }
    }
}
