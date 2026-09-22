<?php

namespace App\Services\Pharmacy;

use App\Actions\Catalog\SetCatalogTariffAction;
use App\Enums\CatalogTariffCategory;
use App\Models\Medicine;
use App\Models\User;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * ADR-170 — le prix et le nom sous lesquels la pharmacie vend un produit.
 *
 * Trois écrans les proposent (entrée manuelle, réception, entrée depuis une
 * réception) : la règle vit ici une seule fois, pour qu'aucun des trois ne
 * réécrive un prix inchangé ni n'accepte deux valeurs pour le même produit.
 */
class MedicineSaleDetails
{
    /** @var array<string, string> */
    private array $prices = [];

    /** @var array<string, string> */
    private array $names = [];

    public function __construct(private readonly SetCatalogTariffAction $setTariff) {}

    /**
     * Retient ce qu'une ligne demande, en refusant qu'une ligne plus bas dise
     * autre chose pour le même produit.
     */
    public function collect(string $medicineUuid, mixed $price, mixed $name, string $field, int $index): void
    {
        $name = trim((string) $name);

        if ($name !== '') {
            $previous = $this->names[$medicineUuid] ?? null;

            if ($previous !== null && $previous !== $name) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.sale_name" => sprintf('Ligne %d : ce médicament a déjà un autre nom plus haut dans la liste.', $index + 1),
                ]);
            }

            $this->names[$medicineUuid] = $name;
        }

        if (filled($price)) {
            $previous = $this->prices[$medicineUuid] ?? null;

            if ($previous !== null && Money::toMinor($previous) !== Money::toMinor((string) $price)) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.sale_price" => sprintf('Ligne %d : ce médicament a déjà un autre prix de vente plus haut dans la liste.', $index + 1),
                ]);
            }

            $this->prices[$medicineUuid] = (string) $price;
        }
    }

    /**
     * Applique ce qui a été retenu. Un prix inchangé n'est pas réécrit ; un
     * prix changé ferme l'ancien, qui reste dans l'historique. Seul le nom du
     * catalogue clinique change : le libellé fournisseur reste sur sa ligne
     * de catalogue, ventes et factures passées gardent leur instantané, et
     * CatalogItem est Auditable.
     */
    public function apply(string $reason, User $actor): void
    {
        foreach ($this->prices as $medicineUuid => $amount) {
            $item = Medicine::query()->where('uuid', $medicineUuid)->firstOrFail()->catalogItem;
            $current = $item->currentStandardTariff?->amount;

            if ($current !== null && Money::toMinor((string) $current) === Money::toMinor($amount)) {
                continue;
            }

            $this->setTariff->execute(
                $item,
                CatalogTariffCategory::Standard,
                $amount,
                "Prix de vente fixé à l’entrée de stock : {$reason}",
                CatalogActor::fromUser($actor),
            );
        }

        foreach ($this->names as $medicineUuid => $name) {
            $item = Medicine::query()->where('uuid', $medicineUuid)->firstOrFail()->catalogItem;

            if ($item->name !== $name) {
                $item->update(['name' => $name, 'updated_by' => $actor->getKey()]);
            }
        }

        $this->prices = [];
        $this->names = [];
    }
}
