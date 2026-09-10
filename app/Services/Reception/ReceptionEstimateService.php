<?php

namespace App\Services\Reception;

use App\Enums\CatalogItemType;
use App\Enums\CatalogTariffCategory;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
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
                    'lines' => 'Chaque prestation doit être fournie une seule fois avec un UUID valide.',
                ]);
            }

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

            $normalized->put($uuid, $quantity);
        }

        $items = CatalogItem::query()
            ->whereIn('uuid', $normalized->keys())
            ->where('type', CatalogItemType::Service->value)
            ->where('billable', true)
            ->where('reception_selectable', true)
            ->whereNotNull('reception_routing_mode')
            ->with(['currentStandardTariff' => fn ($query) => $query->select([
                'id', 'catalog_item_id', 'tariff_category', 'amount', 'currency',
            ])])
            ->get()
            ->keyBy('uuid');

        if ($items->count() !== $normalized->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Une prestation sélectionnée est indisponible ou non proposée à la Réception.',
            ]);
        }

        $totalMinor = 0;
        $estimatedLines = $normalized->map(function (string $quantity, string $uuid) use ($items, &$totalMinor): array {
            /** @var CatalogItem $item */
            $item = $items->get($uuid);
            /** @var CatalogTariff|null $tariff */
            $tariff = $item->currentStandardTariff;

            if (! $tariff) {
                throw ValidationException::withMessages([
                    'lines' => "Le tarif Sans mutuelle de {$item->name} n’est pas configuré.",
                ]);
            }

            $lineMinor = Money::multiply($quantity, $tariff->amount);
            $totalMinor += $lineMinor;

            return [
                'catalog_item_uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => $quantity,
                'unit_price' => $tariff->amount,
                'line_total' => Money::fromMinor($lineMinor),
                'currency' => $tariff->currency,
            ];
        })->values()->all();

        return [
            'tariff_category' => CatalogTariffCategory::Standard->value,
            'currency' => 'MGA',
            'lines' => $estimatedLines,
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
