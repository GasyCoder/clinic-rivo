<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UpdateCatalogItemAction
{
    /** @param array<string, mixed> $data */
    public function execute(CatalogItem $item, array $data, User $actor): CatalogItem
    {
        if ($actor->cannot('catalog.items.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier le référentiel.');
        }

        $item->fill([
            'name' => trim($data['name']),
            'module' => $data['module'],
            'unit' => trim($data['unit']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'updated_by' => $actor->id,
        ])->save();

        return $item->fresh(['currentTariff']);
    }
}
