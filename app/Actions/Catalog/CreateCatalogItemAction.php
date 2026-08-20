<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogItemType;
use App\Models\CatalogItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCatalogItemAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, User $actor): CatalogItem
    {
        if ($actor->cannot('catalog.items.create')) {
            throw new AuthorizationException('Vous ne pouvez pas créer un élément du référentiel.');
        }

        $type = CatalogItemType::from($data['type']);
        $billable = (bool) $data['billable'];
        $stockable = (bool) $data['stockable'];
        $this->assertTypeRules($type, $billable, $stockable);

        if ($billable && $actor->cannot('catalog.tariffs.create')) {
            throw new AuthorizationException('Vous ne pouvez pas définir le tarif initial.');
        }

        return DB::transaction(function () use ($data, $actor, $type, $billable, $stockable) {
            $item = CatalogItem::create([
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'type' => $type,
                'module' => $data['module'],
                'unit' => trim($data['unit']),
                'billable' => $billable,
                'stockable' => $stockable,
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($billable) {
                $amountMinor = Money::toMinor($data['tariff_amount']);

                if ($amountMinor <= 0) {
                    throw ValidationException::withMessages([
                        'tariff_amount' => 'Le tarif doit être supérieur à zéro.',
                    ]);
                }

                $item->tariffs()->create([
                    'amount' => Money::fromMinor($amountMinor),
                    'currency' => 'MGA',
                    'effective_from' => now(),
                    'active_key' => 'CURRENT',
                    'change_reason' => trim($data['tariff_reason']),
                    'created_by' => $actor->id,
                ]);
            }

            return $item->load('currentTariff');
        });
    }

    private function assertTypeRules(CatalogItemType $type, bool $billable, bool $stockable): void
    {
        $errors = [];

        if ($type->mustBeBillable() && ! $billable) {
            $errors['billable'] = 'Une prestation doit être facturable.';
        }

        if ($type->mustBeStockable() && ! $stockable) {
            $errors['stockable'] = 'Un médicament ou consommable doit être stockable.';
        }

        if (! $type->canBeStockable() && $stockable) {
            $errors['stockable'] = 'Une prestation ou un équipement durable ne se gère pas comme un stock en quantité.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
