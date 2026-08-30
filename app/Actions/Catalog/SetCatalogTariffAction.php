<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogTariffCategory;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetCatalogTariffAction
{
    public function execute(
        CatalogItem $item,
        CatalogTariffCategory $category,
        string|int $amount,
        string $reason,
        CatalogActor $actor,
    ): CatalogTariff {
        return DB::transaction(function () use ($item, $category, $amount, $reason, $actor) {
            $item = CatalogItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $item->billable) {
                throw ValidationException::withMessages([
                    'tariff_amount' => 'Cet élément n’est pas facturable.',
                ]);
            }

            $current = $item->currentTariffFor($category)->lockForUpdate()->first();
            $permission = $current ? 'catalog.tariffs.update' : 'catalog.tariffs.create';

            if ($actor->cannot($permission)) {
                throw new AuthorizationException('Vous ne pouvez pas modifier ce tarif.');
            }

            $amountMinor = Money::toMinor($amount);

            if ($amountMinor <= 0) {
                throw ValidationException::withMessages([
                    'tariff_amount' => 'Le tarif doit être supérieur à zéro.',
                ]);
            }

            if ($current && Money::toMinor($current->amount) === $amountMinor) {
                throw ValidationException::withMessages([
                    'tariff_amount' => 'Le nouveau tarif est identique au tarif actuel.',
                ]);
            }

            $effectiveAt = now();

            if ($current) {
                $current->fill([
                    'effective_until' => $effectiveAt,
                    'active_key' => null,
                    'ended_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('ended'),
                ])->save();
            }

            return $item->tariffs()->create([
                'tariff_category' => $category,
                'amount' => Money::fromMinor($amountMinor),
                'currency' => 'MGA',
                'effective_from' => $effectiveAt,
                'active_key' => 'CURRENT',
                'change_reason' => trim($reason),
                'created_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
            ]);
        });
    }
}
