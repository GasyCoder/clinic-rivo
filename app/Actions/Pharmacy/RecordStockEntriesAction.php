<?php

namespace App\Actions\Pharmacy;

use App\Actions\Catalog\SetCatalogTariffAction;
use App\Enums\CatalogTariffCategory;
use App\Models\Medicine;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-098 — a delivery of several medicines recorded at once. Each line goes
 * through the unchanged single-entry Action (lot rules, expiry, supplier,
 * immutable movement); if one line is refused, nothing is recorded, and the
 * refusal names the line so the pharmacist corrects it in the list.
 */
class RecordStockEntriesAction
{
    public function __construct(
        private readonly RecordStockEntryAction $entry,
        private readonly SetCatalogTariffAction $setTariff,
    ) {}

    /**
     * @param  array<string, mixed>  $delivery  received_at, supplier_uuid, origin, destination, reason
     * @param  array<int, array<string, mixed>>  $entries
     */
    public function execute(array $delivery, array $entries, User $actor): int
    {
        return DB::transaction(function () use ($delivery, $entries, $actor): int {
            $salePrices = [];
            $saleNames = [];

            foreach (array_values($entries) as $index => $line) {
                $salePrice = $line['sale_price'] ?? null;
                $saleName = trim((string) ($line['sale_name'] ?? ''));
                unset($line['sale_price'], $line['sale_name']);

                if ($saleName !== '') {
                    $previousName = $saleNames[$line['medicine_uuid']] ?? null;

                    if ($previousName !== null && $previousName !== $saleName) {
                        throw ValidationException::withMessages([
                            "entries.{$index}.sale_name" => sprintf('Ligne %d : ce médicament a déjà un autre nom plus haut dans la liste.', $index + 1),
                        ]);
                    }

                    $saleNames[$line['medicine_uuid']] = $saleName;
                }

                if (filled($salePrice)) {
                    $previous = $salePrices[$line['medicine_uuid']] ?? null;

                    if ($previous !== null && Money::toMinor((string) $previous) !== Money::toMinor((string) $salePrice)) {
                        throw ValidationException::withMessages([
                            "entries.{$index}.sale_price" => sprintf('Ligne %d : ce médicament a déjà un autre prix de vente plus haut dans la liste.', $index + 1),
                        ]);
                    }

                    $salePrices[$line['medicine_uuid']] = $salePrice;
                }

                try {
                    $this->entry->execute([...$delivery, ...$line], $actor);
                } catch (ValidationException $exception) {
                    $name = Medicine::query()->with('catalogItem:id,name')->where('uuid', $line['medicine_uuid'])->first()?->catalogItem?->name ?? 'Médicament';

                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                        fn (array $messages, string $field) => ["entries.{$index}.{$field}" => sprintf('Ligne %d (%s) : %s', $index + 1, $name, $messages[0])],
                    )->all());
                }
            }

            $this->applySalePrices($salePrices, (string) $delivery['reason'], $actor);
            $this->applySaleNames($saleNames, $actor);

            return count($entries);
        });
    }

    /**
     * ADR-112 — le nom sous lequel la pharmacie vend un produit peut différer
     * du libellé du fournisseur. Seul le nom du catalogue clinique change :
     * le libellé fournisseur reste sur sa ligne de catalogue, et ventes et
     * factures passées gardent leur instantané. CatalogItem est Auditable,
     * l'ancien nom reste donc lisible à l'audit.
     *
     * @param  array<string, string>  $saleNames
     */
    private function applySaleNames(array $saleNames, User $actor): void
    {
        foreach ($saleNames as $medicineUuid => $name) {
            $item = Medicine::query()->where('uuid', $medicineUuid)->firstOrFail()->catalogItem;

            if ($item->name === $name) {
                continue;
            }

            $item->update(['name' => $name, 'updated_by' => $actor->getKey()]);
        }
    }

    /**
     * ADR-112 — un prix de vente donné à l'entrée devient le tarif Standard
     * du médicament. Inchangé, il n'est pas réécrit ; changé, l'ancien reste
     * dans l'historique et le motif de la livraison sert de motif.
     *
     * @param  array<string, string|int|float>  $salePrices
     */
    private function applySalePrices(array $salePrices, string $reason, User $actor): void
    {
        foreach ($salePrices as $medicineUuid => $amount) {
            $item = Medicine::query()->where('uuid', $medicineUuid)->firstOrFail()->catalogItem;
            $current = $item->currentStandardTariff?->amount;

            if ($current !== null && Money::toMinor((string) $current) === Money::toMinor((string) $amount)) {
                continue;
            }

            $this->setTariff->execute(
                $item,
                CatalogTariffCategory::Standard,
                (string) $amount,
                "Prix de vente fixé à l’entrée de stock : {$reason}",
                CatalogActor::fromUser($actor),
            );
        }
    }
}
