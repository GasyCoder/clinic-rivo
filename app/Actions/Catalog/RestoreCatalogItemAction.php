<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class RestoreCatalogItemAction
{
    public function execute(CatalogItem $item, CatalogActor $actor): CatalogItem
    {
        if ($actor->cannot('catalog.items.restore')) {
            throw new AuthorizationException('Vous ne pouvez pas restaurer cet élément.');
        }

        $item->fill([
            'updated_by' => $actor->localUserId(),
            ...$actor->externalAttribution('updated'),
        ]);
        $item->restore();

        return $item->fresh(['currentTariff']);
    }
}
