<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ArchiveCatalogItemAction
{
    public function execute(CatalogItem $item, string $reason, User $actor): void
    {
        if ($actor->cannot('catalog.items.delete')) {
            throw new AuthorizationException('Vous ne pouvez pas archiver cet élément.');
        }

        $item->delete_reason = trim($reason);
        $item->delete();
    }
}
