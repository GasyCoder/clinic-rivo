<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;

class ArchiveCatalogItemAction
{
    public function execute(CatalogItem $item, string $reason, CatalogActor $actor): void
    {
        if ($actor->cannot('catalog.items.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver cet élément.');
        }

        $item->delete_reason = trim($reason);
        $item->delete();
    }
}
