<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionRoutingMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\CatalogItem;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCatalogItemAction
{
    /** @param array<string, mixed> $data */
    public function execute(array $data, CatalogActor $actor): CatalogItem
    {
        if ($actor->cannot('catalog.items.create')) {
            throw new AuthorizationException('Vous ne pouvez pas créer un élément du référentiel.');
        }

        $type = CatalogItemType::from($data['type']);
        $billable = (bool) $data['billable'];
        $stockable = (bool) $data['stockable'];
        $this->assertTypeRules($type, $billable, $stockable);
        $staffCoveragePolicy = StaffCoveragePolicy::tryFrom(
            (string) ($data['staff_coverage_policy'] ?? StaffCoveragePolicy::Unclassified->value),
        ) ?? StaffCoveragePolicy::Unclassified;
        $this->assertStaffCoveragePolicy($billable, $staffCoveragePolicy);
        [$receptionSelectable, $routingMode] = $this->receptionRouting($type, $billable, $data);
        [$requiresAllergyCheck, $recommendsVitals, $clinicianOrderable] = $this->careRequirements($type, $data);

        if ($billable && $actor->cannot('catalog.tariffs.create')) {
            throw new AuthorizationException('Vous ne pouvez pas définir le tarif initial.');
        }

        return DB::transaction(function () use ($data, $actor, $type, $billable, $stockable, $staffCoveragePolicy, $receptionSelectable, $routingMode, $requiresAllergyCheck, $recommendsVitals, $clinicianOrderable) {
            $item = CatalogItem::create([
                'code' => mb_strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'type' => $type,
                'module' => $data['module'],
                'unit' => trim($data['unit']),
                'billable' => $billable,
                'stockable' => $stockable,
                'staff_coverage_policy' => $staffCoveragePolicy,
                'reception_selectable' => $receptionSelectable,
                'reception_routing_mode' => $routingMode,
                'care_requires_allergy_check' => $requiresAllergyCheck,
                'care_recommends_vitals' => $recommendsVitals,
                'clinician_orderable' => $clinicianOrderable,
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'created_by' => $actor->localUserId(),
                'updated_by' => $actor->localUserId(),
                ...$actor->externalAttribution('created'),
                ...$actor->externalAttribution('updated'),
            ]);

            if ($billable) {
                $amountMinor = Money::toMinor($data['tariff_amount']);

                if ($amountMinor <= 0) {
                    throw ValidationException::withMessages([
                        'tariff_amount' => 'Le tarif doit être supérieur à zéro.',
                    ]);
                }

                $item->tariffs()->create([
                    'tariff_category' => CatalogTariffCategory::Standard,
                    'amount' => Money::fromMinor($amountMinor),
                    'currency' => 'MGA',
                    'effective_from' => now(),
                    'active_key' => 'CURRENT',
                    'change_reason' => trim($data['tariff_reason']),
                    'created_by' => $actor->localUserId(),
                    ...$actor->externalAttribution('created'),
                ]);

                if (filled($data['mutual_tariff_amount'] ?? null)) {
                    $mutualAmountMinor = Money::toMinor($data['mutual_tariff_amount']);

                    if ($mutualAmountMinor <= 0) {
                        throw ValidationException::withMessages([
                            'mutual_tariff_amount' => 'Le tarif mutuelle doit être supérieur à zéro.',
                        ]);
                    }

                    $item->tariffs()->create([
                        'tariff_category' => CatalogTariffCategory::Mutual,
                        'amount' => Money::fromMinor($mutualAmountMinor),
                        'currency' => 'MGA',
                        'effective_from' => now(),
                        'active_key' => 'CURRENT',
                        'change_reason' => trim($data['tariff_reason']),
                        'created_by' => $actor->localUserId(),
                        ...$actor->externalAttribution('created'),
                    ]);
                }
            }

            return $item->load('currentStandardTariff', 'currentMutualTariff');
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

    private function assertStaffCoveragePolicy(bool $billable, StaffCoveragePolicy $policy): void
    {
        if (! $billable && $policy !== StaffCoveragePolicy::Unclassified) {
            throw ValidationException::withMessages([
                'staff_coverage_policy' => 'Une politique Personnel ne peut être appliquée qu’à un élément facturable.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: bool, 1: ?ReceptionRoutingMode}
     */
    private function receptionRouting(CatalogItemType $type, bool $billable, array $data): array
    {
        $selectable = (bool) ($data['reception_selectable'] ?? false);
        $route = filled($data['reception_routing_mode'] ?? null)
            ? ReceptionRoutingMode::from((string) $data['reception_routing_mode'])
            : null;

        if (! $selectable && $route !== null) {
            throw ValidationException::withMessages([
                'reception_routing_mode' => 'Un parcours ne peut être défini que pour une prestation disponible à la Réception.',
            ]);
        }

        if ($selectable && ($type !== CatalogItemType::Service || ! $billable)) {
            throw ValidationException::withMessages([
                'reception_selectable' => 'Seule une prestation facturable peut être proposée à la Réception.',
            ]);
        }

        if ($selectable && $route === null) {
            throw ValidationException::withMessages([
                'reception_routing_mode' => 'Le parcours clinique est obligatoire pour une prestation proposée à la Réception.',
            ]);
        }

        return [$selectable, $route];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: bool, 1: bool}
     */
    private function careRequirements(CatalogItemType $type, array $data): array
    {
        $isCareService = $type === CatalogItemType::Service
            && ($data['module'] ?? null) === CatalogModule::Care->value;
        $requiresAllergyCheck = (bool) ($data['care_requires_allergy_check'] ?? false);
        $recommendsVitals = (bool) ($data['care_recommends_vitals'] ?? false);
        $clinicianOrderable = (bool) ($data['clinician_orderable'] ?? false);

        if (! $isCareService && ($requiresAllergyCheck || $recommendsVitals || $clinicianOrderable)) {
            throw ValidationException::withMessages([
                'care_requires_allergy_check' => 'Ces exigences sont réservées aux prestations du module Soins.',
            ]);
        }

        return [
            $isCareService && $requiresAllergyCheck,
            $isCareService && $recommendsVitals,
            $isCareService && $clinicianOrderable,
        ];
    }
}
