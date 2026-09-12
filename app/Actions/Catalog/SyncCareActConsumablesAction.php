<?php

namespace App\Actions\Catalog;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Enums\MedicineForm;
use App\Models\CareActConsumable;
use App\Models\CatalogItem;
use App\Models\Medicine;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-072 — configures the material usually consumed by a nursing act.
 *
 * Catalogue configuration, so it requires `catalog.items.update` (ADR-024)
 * and is audited. It never touches stock, price, or any declaration already
 * made: replacing this list only changes what a future entry suggests.
 */
class SyncCareActConsumablesAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<int, array{medicine_uuid: string, default_quantity: int|string}> $lines */
    public function execute(CatalogItem $item, array $lines, CatalogActor $actor): CatalogItem
    {
        if ($actor->cannot('catalog.items.update')) {
            throw new AuthorizationException('Vous ne pouvez pas modifier le référentiel.');
        }

        return DB::transaction(function () use ($item, $lines, $actor): CatalogItem {
            $item = CatalogItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($item->type !== CatalogItemType::Service || $item->module !== CatalogModule::Care) {
                throw ValidationException::withMessages([
                    'catalog_item' => 'Seul un acte de soins peut recevoir du matériel habituel.',
                ]);
            }

            $submitted = collect($lines)->keyBy('medicine_uuid');
            $medicines = Medicine::query()
                ->whereIn('uuid', $submitted->keys())
                ->where('active', true)
                ->where('form', MedicineForm::ParapharmacyConsumable->value)
                ->whereHas('catalogItem', fn ($query) => $query
                    ->where('type', CatalogItemType::Medicine->value)
                    ->where('module', CatalogModule::Pharmacy->value)
                    ->where('stockable', true))
                ->get()
                ->keyBy('uuid');

            if ($medicines->count() !== $submitted->count()) {
                throw ValidationException::withMessages([
                    'consumables' => 'Seuls les consommables de parapharmacie actifs peuvent être associés à un acte de soins.',
                ]);
            }

            $before = $this->snapshot($item);
            $keptIds = [];
            $position = 0;

            foreach ($lines as $line) {
                $medicine = $medicines->get($line['medicine_uuid']);
                $row = CareActConsumable::query()->firstOrNew([
                    'catalog_item_id' => $item->getKey(),
                    'medicine_id' => $medicine->getKey(),
                ]);

                if (! $row->exists) {
                    $row->created_by = $actor->localUserId();
                }

                $row->fill([
                    'default_quantity' => max(1, (int) $line['default_quantity']),
                    'position' => $position++,
                    'updated_by' => $actor->localUserId(),
                ])->save();

                $keptIds[] = $row->getKey();
            }

            // Removing a suggestion is a configuration correction, not the
            // destruction of a clinical record: nothing already declared by
            // a nurse references these rows.
            $item->defaultConsumables()->whereNotIn('id', $keptIds ?: [0])->delete();

            $this->auditor->record(
                'catalog.care_act_consumables.update',
                entity: $item,
                newValues: ['consumables' => $this->snapshot($item->fresh())],
                oldValues: ['consumables' => $before],
                module: 'administration',
                actor: $actor->user(),
            );

            return $item->fresh(['defaultConsumables.medicine.catalogItem']);
        });
    }

    /** @return array<int, array{name: ?string, quantity: int}> */
    private function snapshot(CatalogItem $item): array
    {
        return $item->defaultConsumables()
            ->with('medicine.catalogItem:id,name')
            ->get()
            ->map(fn (CareActConsumable $row) => [
                'name' => $row->medicine->catalogItem?->name,
                'quantity' => $row->default_quantity,
            ])
            ->all();
    }
}
