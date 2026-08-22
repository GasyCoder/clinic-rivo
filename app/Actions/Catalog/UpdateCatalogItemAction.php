<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogItemType;
use App\Enums\ReceptionRoutingMode;
use App\Models\CatalogItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class UpdateCatalogItemAction
{
    /** @param array<string, mixed> $data */
    public function execute(CatalogItem $item, array $data, User $actor): CatalogItem
    {
        if ($actor->cannot('catalog.items.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier le référentiel.');
        }

        $selectable = array_key_exists('reception_selectable', $data)
            ? (bool) $data['reception_selectable']
            : $item->reception_selectable;
        $route = array_key_exists('reception_routing_mode', $data)
            ? (filled($data['reception_routing_mode'])
                ? ReceptionRoutingMode::from((string) $data['reception_routing_mode'])
                : null)
            : $item->reception_routing_mode;

        if (! $selectable) {
            $route = null;
        } elseif ($item->type !== CatalogItemType::Service || ! $item->billable) {
            throw ValidationException::withMessages([
                'reception_selectable' => 'Seule une prestation facturable peut être proposée à la Réception.',
            ]);
        } elseif ($route === null) {
            throw ValidationException::withMessages([
                'reception_routing_mode' => 'Le parcours clinique est obligatoire pour une prestation proposée à la Réception.',
            ]);
        }

        $item->fill([
            'name' => trim($data['name']),
            'module' => $data['module'],
            'unit' => trim($data['unit']),
            'reception_selectable' => $selectable,
            'reception_routing_mode' => $route,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'updated_by' => $actor->id,
        ])->save();

        return $item->fresh(['currentTariff']);
    }
}
