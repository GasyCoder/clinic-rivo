<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\CatalogItem;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class UpdateCatalogItemAction
{
    /** @param array<string, mixed> $data */
    public function execute(CatalogItem $item, array $data, CatalogActor $actor): CatalogItem
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

        $isCareService = $item->type === CatalogItemType::Service
            && ($data['module'] ?? null) === CatalogModule::Care->value;
        $requiresAllergyCheck = (bool) ($data['care_requires_allergy_check'] ?? false);
        $recommendsVitals = (bool) ($data['care_recommends_vitals'] ?? false);
        $staffCoveragePolicy = array_key_exists('staff_coverage_policy', $data)
            ? StaffCoveragePolicy::from((string) $data['staff_coverage_policy'])
            : $item->staff_coverage_policy;

        if (! $item->billable && $staffCoveragePolicy !== StaffCoveragePolicy::Unclassified) {
            throw ValidationException::withMessages([
                'staff_coverage_policy' => 'Une politique Personnel ne peut être appliquée qu’à un élément facturable.',
            ]);
        }

        if (! $isCareService && ($requiresAllergyCheck || $recommendsVitals)) {
            throw ValidationException::withMessages([
                'care_requires_allergy_check' => 'Ces exigences sont réservées aux prestations du module Soins.',
            ]);
        }

        $item->fill([
            'name' => trim($data['name']),
            'module' => $data['module'],
            'unit' => trim($data['unit']),
            'reception_selectable' => $selectable,
            'reception_routing_mode' => $route,
            'staff_coverage_policy' => $staffCoveragePolicy,
            'care_requires_allergy_check' => $isCareService && $requiresAllergyCheck,
            'care_recommends_vitals' => $isCareService && $recommendsVitals,
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'updated_by' => $actor->localUserId(),
            ...$actor->externalAttribution('updated'),
        ])->save();

        return $item->fresh(['currentTariff']);
    }
}
