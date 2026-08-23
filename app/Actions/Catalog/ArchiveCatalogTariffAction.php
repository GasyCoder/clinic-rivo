<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogTariffCategory;
use App\Models\CatalogItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveCatalogTariffAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(
        CatalogItem $item,
        CatalogTariffCategory $category,
        string $reason,
        User $actor,
    ): void {
        if ($actor->cannot('catalog.tariffs.archive')) {
            throw new AuthorizationException('Vous ne pouvez pas suspendre ce tarif.');
        }

        DB::transaction(function () use ($item, $category, $reason, $actor) {
            $item = CatalogItem::query()->lockForUpdate()->findOrFail($item->id);
            $current = $item->currentTariffFor($category)->lockForUpdate()->first();

            if (! $current) {
                throw ValidationException::withMessages([
                    'tariff' => "Aucun tarif {$category->label()} actif à suspendre.",
                ]);
            }

            $current->fill([
                'effective_until' => now(),
                'active_key' => null,
                'ended_by' => $actor->id,
            ])->save();

            $this->auditor->record(
                'catalog.tariff.archive',
                entity: $current,
                oldValues: ['active' => true],
                newValues: ['active' => false],
                reason: trim($reason),
                module: 'catalog',
                actor: $actor,
            );
        });
    }
}
