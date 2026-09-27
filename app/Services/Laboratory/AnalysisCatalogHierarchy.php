<?php

namespace App\Services\Laboratory;

use App\Models\AnalysisCatalog;
use Illuminate\Support\Collection;

class AnalysisCatalogHierarchy
{
    /**
     * @return array<int, array{depth: int, path: string}>
     */
    public function metadata(): array
    {
        $items = AnalysisCatalog::query()
            ->get(['id', 'parent_id', 'code', 'designation'])
            ->keyBy('id');
        $resolved = [];

        foreach ($items as $item) {
            $this->resolve($item, $items, $resolved, []);
        }

        return $resolved;
    }

    /** @return array<int, array<string, mixed>> */
    public function parentOptions(): array
    {
        $metadata = $this->metadata();

        return AnalysisCatalog::query()
            ->where('level', AnalysisCatalog::CONTAINER_LEVEL)
            ->where('is_active', true)
            ->with(['catalogItem:id,uuid', 'parent:id,uuid'])
            ->get()
            ->sortBy(fn (AnalysisCatalog $item): string => mb_strtolower($metadata[$item->id]['path'] ?? $item->designation))
            ->map(fn (AnalysisCatalog $item): array => [
                'uuid' => $item->uuid,
                'catalog_item_uuid' => $item->catalogItem->uuid,
                'parent_uuid' => $item->parent?->uuid,
                'code' => $item->code,
                'designation' => $item->designation,
                'path' => $metadata[$item->id]['path'] ?? $item->designation,
                'depth' => $metadata[$item->id]['depth'] ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AnalysisCatalog>  $items
     * @param  array<int, array{depth: int, path: string}>  $resolved
     * @param  array<int, true>  $visiting
     * @return array{depth: int, path: string}
     */
    private function resolve(
        AnalysisCatalog $item,
        Collection $items,
        array &$resolved,
        array $visiting,
    ): array {
        if (isset($resolved[$item->id])) {
            return $resolved[$item->id];
        }

        if (isset($visiting[$item->id])) {
            return $resolved[$item->id] = ['depth' => 0, 'path' => $item->designation];
        }

        $visiting[$item->id] = true;
        $parent = $item->parent_id ? $items->get($item->parent_id) : null;
        if (! $parent) {
            return $resolved[$item->id] = ['depth' => 0, 'path' => $item->designation];
        }

        $parentMetadata = $this->resolve($parent, $items, $resolved, $visiting);

        return $resolved[$item->id] = [
            'depth' => $parentMetadata['depth'] + 1,
            'path' => $parentMetadata['path'].' › '.$item->designation,
        ];
    }
}
