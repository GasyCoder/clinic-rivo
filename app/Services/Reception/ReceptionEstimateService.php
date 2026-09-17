<?php

namespace App\Services\Reception;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\CatalogTariffCategory;
use App\Enums\ReceptionCartKind;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Services\Pharmacy\MedicineStockOverviewService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Read-only Standard estimate before any Patient or Episode exists.
 *
 * Every amount is read again from the current server-side catalog. Extra
 * browser fields (including a forged unit_price) are intentionally ignored.
 */
class ReceptionEstimateService
{
    public function __construct(
        private readonly MedicineStockOverviewService $stockOverview,
    ) {}

    /**
     * Le rayon Pharmacie du panier (ADR-104).
     *
     * Même liste et mêmes garde-fous que la vente comptoir qu'elle
     * remplace — actif, facturable, prix de vente configuré, stock
     * disponible — lus par `MedicineStockOverviewService`, l'unique
     * définition de « ce qu'un lot tient encore » depuis l'ADR-098. En
     * écrire une seconde ici ferait diverger ce que la Réception propose et
     * ce que la Pharmacie peut réellement sortir.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pharmacyCatalog(): Collection
    {
        return collect($this->stockOverview->overview()['medicines'])
            ->filter(fn (array $medicine): bool => $medicine['active']
                && $medicine['billable']
                && filled($medicine['sale_price'])
                && $medicine['available_quantity'] > 0)
            ->map(fn (array $medicine): array => [
                'kind' => ReceptionCartKind::Medicine->value,
                // L'UUID du `catalog_item` est la clé universelle du
                // panier : les deux rayons se chiffrent alors par le même
                // résolveur tarifaire, et rien n'a à savoir lequel est un
                // médicament pour en lire le prix.
                'catalog_item_uuid' => $medicine['catalog_uuid'],
                'medicine_uuid' => $medicine['uuid'],
                'code' => $medicine['code'],
                'name' => $medicine['name'],
                'generic_name' => $medicine['generic_name'],
                'form_label' => $medicine['form_label'],
                'strength' => $medicine['strength'],
                'module' => CatalogModule::Pharmacy->value,
                'module_label' => CatalogModule::Pharmacy->label(),
                'unit' => $medicine['unit'],
                'category' => $medicine['category']['name'] ?? null,
                'prescription_required' => $medicine['prescription_required'],
                'available_quantity' => $medicine['available_quantity'],
                'tariff_category' => CatalogTariffCategory::Standard->value,
                'tariff_available' => true,
                'reception_ready' => true,
                'unit_price' => $medicine['sale_price'],
                'currency' => $medicine['currency'] ?? 'MGA',
            ])
            ->values();
    }

    /**
     * Every active, billable Service — not only the ones Reception can
     * actually route today — so every domain of the referential is visible
     * for browsing. `reception_ready` (see catalogLine()) is the strict,
     * server-authoritative gate: only it decides what estimate()/addService
     * may accept, exactly as ADR-053 requires ("la liste et les destinations
     * ne sont jamais codées dans Vue"). A domain with no Reception workflow
     * yet (e.g. Chirurgie, direct to bloc) stays visible but never becomes
     * selectable here — nothing is routed without a real destination.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function catalog(): Collection
    {
        return CatalogItem::query()
            ->where('type', CatalogItemType::Service->value)
            ->where('billable', true)
            ->with(['currentStandardTariff' => fn ($query) => $query->select([
                'id', 'catalog_item_id', 'tariff_category', 'amount', 'currency',
            ])])
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogItem $item) => $this->catalogLine($item))
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{tariff_category: string, currency: string, lines: array<int, array<string, mixed>>, total_amount: string}
     */
    public function estimate(array $lines): array
    {
        if ($lines === [] || count($lines) > 50) {
            throw ValidationException::withMessages([
                'lines' => 'Sélectionnez entre une et cinquante prestations à estimer.',
            ]);
        }

        $normalized = collect();

        foreach ($lines as $line) {
            $uuid = trim((string) ($line['catalog_item_uuid'] ?? ''));

            if ($uuid === '' || $normalized->has($uuid)) {
                throw ValidationException::withMessages([
                    'lines' => 'Chaque ligne doit être fournie une seule fois avec un UUID valide.',
                ]);
            }

            $kind = ReceptionCartKind::fromLine($line);

            try {
                $quantity = Money::normalize($line['quantity'] ?? '');
            } catch (InvalidArgumentException|OverflowException) {
                throw ValidationException::withMessages([
                    'lines' => 'La quantité d’une prestation est invalide.',
                ]);
            }

            if (Money::toMinor($quantity) <= 0 || Money::toMinor($quantity) > 999_999) {
                throw ValidationException::withMessages([
                    'lines' => 'La quantité doit être supérieure à zéro et ne pas dépasser 9 999,99.',
                ]);
            }

            if ($kind === ReceptionCartKind::Medicine
                && Money::toMinor($quantity) % 100 !== 0) {
                // Une boîte ne se vend pas par fraction : la quantité d'un
                // médicament est un entier, contrairement à une prestation
                // qui peut se compter en unités décimales.
                throw ValidationException::withMessages([
                    'lines' => 'La quantité d’un médicament doit être un nombre entier.',
                ]);
            }

            $normalized->put($uuid, ['quantity' => $quantity, 'kind' => $kind]);
        }

        $serviceUuids = $normalized->filter(fn (array $line) => $line['kind'] === ReceptionCartKind::Service)->keys();
        $medicineUuids = $normalized->filter(fn (array $line) => $line['kind'] === ReceptionCartKind::Medicine)->keys();

        $items = collect();

        if ($serviceUuids->isNotEmpty()) {
            $services = CatalogItem::query()
                ->whereIn('uuid', $serviceUuids)
                ->where('type', CatalogItemType::Service->value)
                ->where('billable', true)
                ->where('reception_selectable', true)
                ->whereNotNull('reception_routing_mode')
                ->with(['currentStandardTariff' => fn ($query) => $query->select([
                    'id', 'catalog_item_id', 'tariff_category', 'amount', 'currency',
                ])])
                ->get()
                ->keyBy('uuid');

            if ($services->count() !== $serviceUuids->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Une prestation sélectionnée est indisponible ou non proposée à la Réception.',
                ]);
            }

            $items = $items->merge($services);
        }

        if ($medicineUuids->isNotEmpty()) {
            // Le rayon Pharmacie a ses propres garde-fous : un produit
            // stockable du module Pharmacie, adossé à une fiche médicament
            // active. `reception_selectable`/`reception_routing_mode` ne
            // s'appliquent pas — ils décrivent un parcours clinique, qu'un
            // médicament n'ouvre jamais (ADR-104).
            $medicines = CatalogItem::query()
                ->whereIn('uuid', $medicineUuids)
                ->where('type', CatalogItemType::Medicine->value)
                ->where('module', CatalogModule::Pharmacy->value)
                ->where('stockable', true)
                ->where('billable', true)
                ->whereHas('medicine', fn ($query) => $query->where('active', true))
                ->with(['currentStandardTariff' => fn ($query) => $query->select([
                    'id', 'catalog_item_id', 'tariff_category', 'amount', 'currency',
                ])])
                ->get()
                ->keyBy('uuid');

            if ($medicines->count() !== $medicineUuids->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'Un médicament sélectionné est inactif, non facturable ou absent du référentiel Pharmacie.',
                ]);
            }

            $items = $items->merge($medicines);
        }

        $totalMinor = 0;
        $servicesMinor = 0;
        $medicinesMinor = 0;

        $estimatedLines = $normalized->map(function (array $line, string $uuid) use (
            $items,
            &$totalMinor,
            &$servicesMinor,
            &$medicinesMinor,
        ): array {
            /** @var CatalogItem $item */
            $item = $items->get($uuid);
            /** @var CatalogTariff|null $tariff */
            $tariff = $item->currentStandardTariff;
            $kind = $line['kind'];

            if (! $tariff) {
                throw ValidationException::withMessages([
                    'lines' => $kind === ReceptionCartKind::Medicine
                        ? "Le prix de vente de {$item->name} n’est pas configuré."
                        : "Le tarif Sans mutuelle de {$item->name} n’est pas configuré.",
                ]);
            }

            $lineMinor = Money::multiply($line['quantity'], $tariff->amount);
            $totalMinor += $lineMinor;

            if ($kind === ReceptionCartKind::Medicine) {
                $medicinesMinor += $lineMinor;
            } else {
                $servicesMinor += $lineMinor;
            }

            return [
                'kind' => $kind->value,
                'catalog_item_uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => $line['quantity'],
                'unit_price' => $tariff->amount,
                'line_total' => Money::fromMinor($lineMinor),
                'currency' => $tariff->currency,
            ];
        })->values()->all();

        // Deux sous-totaux séparés, parce que les deux montants ne se
        // règlent pas sur le même document : la facture du passage d'un
        // côté, le ticket Pharmacie de l'autre (ADR-050, ADR-104).
        return [
            'tariff_category' => CatalogTariffCategory::Standard->value,
            'currency' => 'MGA',
            'lines' => $estimatedLines,
            'services_total' => Money::fromMinor($servicesMinor),
            'medicines_total' => Money::fromMinor($medicinesMinor),
            'total_amount' => Money::fromMinor($totalMinor),
        ];
    }

    /** @return array<string, mixed> */
    private function catalogLine(CatalogItem $item): array
    {
        /** @var CatalogTariff|null $tariff */
        $tariff = $item->currentStandardTariff;
        $routable = $item->reception_selectable && $item->reception_routing_mode !== null;

        return [
            'kind' => ReceptionCartKind::Service->value,
            'catalog_item_uuid' => $item->uuid,
            'code' => $item->code,
            'name' => $item->name,
            'module' => $item->module->value,
            'module_label' => $item->module->label(),
            'unit' => $item->unit,
            'routing_mode' => $item->reception_routing_mode?->value,
            'routing_label' => $item->reception_routing_mode?->label() ?? 'Non proposée à la Réception',
            'tariff_category' => CatalogTariffCategory::Standard->value,
            'tariff_available' => $tariff !== null,
            'reception_ready' => $routable && $tariff !== null,
            'unit_price' => $tariff?->amount,
            'currency' => $tariff?->currency ?? 'MGA',
        ];
    }
}
