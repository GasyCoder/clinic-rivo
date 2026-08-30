<?php

namespace App\Services\Laboratory;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\AnalysisCatalog;
use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnalysisCatalogManager
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($data, $actor): AnalysisCatalog {
            [$catalogItem, $parent] = $this->relations($data);

            return AnalysisCatalog::query()->create([
                ...Arr::except($data, ['catalog_item_uuid', 'parent_uuid']),
                'catalog_item_id' => $catalogItem->getKey(),
                'parent_id' => $parent?->getKey(),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(AnalysisCatalog $analysis, array $data, User $actor): AnalysisCatalog
    {
        return DB::transaction(function () use ($analysis, $data, $actor): AnalysisCatalog {
            $locked = AnalysisCatalog::query()->lockForUpdate()->findOrFail($analysis->getKey());
            [$catalogItem, $parent] = $this->relations($data, $locked);

            $locked->update([
                ...Arr::except($data, ['catalog_item_uuid', 'parent_uuid']),
                'catalog_item_id' => $catalogItem->getKey(),
                'parent_id' => $parent?->getKey(),
                'updated_by' => $actor->getKey(),
            ]);

            return $locked->fresh(['catalogItem', 'parent']);
        });
    }

    public function setActive(AnalysisCatalog $analysis, bool $active, User $actor): AnalysisCatalog
    {
        $analysis->update(['is_active' => $active, 'updated_by' => $actor->getKey()]);

        return $analysis->fresh();
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

        if ($parent && $parent->catalog_item_id !== $catalogItem->getKey()) {
            throw ValidationException::withMessages([
                'parent_uuid' => 'Le parent doit appartenir à la même prestation Laboratoire.',
            ]);
        }

        if (($data['level'] ?? null) === 'CHILD' && ! $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Une sous-analyse doit posséder un parent.']);
        }

        if (($data['level'] ?? null) !== 'CHILD' && $parent) {
            throw ValidationException::withMessages(['parent_uuid' => 'Seules les sous-analyses peuvent posséder un parent.']);
        }

        return [$catalogItem, $parent];
    }
}
