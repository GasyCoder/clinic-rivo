<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class RestoreCatalogItemAction
{
    public function execute(CatalogItem $item, User $actor): CatalogItem
    {
        if ($actor->cannot('catalog.items.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer cet élément.');
        }

        $item->updated_by = $actor->id;
        $item->restore();

        return $item->fresh(['currentTariff']);
    }
}
