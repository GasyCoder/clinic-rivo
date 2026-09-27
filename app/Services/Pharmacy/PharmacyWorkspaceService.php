<?php

namespace App\Services\Pharmacy;

use App\Enums\MedicineForm;
use App\Enums\PharmacyStockAdjustmentType;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Models\PharmacyStockAlert;
use App\Models\User;
use App\Services\Care\CareConsumableDirectory;

/**
 * ADR-098 — one method per Pharmacy screen: a page loads only what it shows,
 * instead of the former single workspace loading stock, queues, catalog and
 * alerts for every visit.
 */
class PharmacyWorkspaceService
{
    public function __construct(
        private readonly MedicineStockOverviewService $stockOverview,
        private readonly PharmacyPrescriptionQueueService $prescriptionQueue,
        private readonly CareConsumableDirectory $careConsumables,
        private readonly ReceivedStockQueue $receivedStock,
    ) {}

    /** @return array<string, bool> */
    public function capabilities(User $user): array
    {
        $canViewStock = $user->can('stock.view');

        return [
            'can_view_stock' => $canViewStock,
            'can_view_lots' => $user->can('stock.lots.view'),
            'can_view_expiration' => $user->can('stock.expiration.view'),
            'can_view_prescriptions' => $user->can('prescriptions.view'),
            // The write permission remains the backend authority. Requiring
            // stock.view here only prevents rendering an unusable form whose
            // medication selector would intentionally contain no stock data.
            'can_record_entry' => $canViewStock && $user->can('stock.entry'),
            'can_create_lot' => $user->can('stock.lots.create'),
            'can_dispense' => $user->can('pharmacy.dispense'),
            'can_prepare_invoice' => $user->can('pharmacy.dispense.prepare_invoice'),
            'can_print_ticket' => $user->can('pharmacy.dispense.print'),
            'can_adjust_stock' => $user->can('stock.adjust'),
            'can_view_alerts' => $user->can('stock.alerts.view'),
            'can_view_suppliers' => $user->can('medicine_suppliers.view'),
            'can_view_categories' => $user->can('medicine_categories.view'),
            'can_view_medicines' => $user->can('medicines.view'),
            'can_view_cost' => $user->can('stock.cost.view'),
            'can_record_cost' => $user->can('stock.cost.record'),
            'can_create_category' => $user->can('medicine_categories.create'),
            'can_update_category' => $user->can('medicine_categories.update'),
            'can_archive_category' => $user->can('medicine_categories.delete'),
            'can_restore_category' => $user->can('medicine_categories.restore'),
            'can_set_sale_price' => $user->can('medicines.sale_price.update'),
            'can_rename_medicine' => $user->can('medicines.name.update'),
            'can_update_medicine' => $user->can('medicines.update') && $user->can('catalog.items.update'),
            'can_create_supplier' => $user->can('medicine_suppliers.create'),
            'can_create_medicine' => $user->can('medicines.create')
                && $user->can('catalog.items.create')
                && $user->can('catalog.tariffs.create'),
            'can_import_medicines' => $user->can('medicines.import')
                && $user->can('medicines.create')
                && $user->can('catalog.items.create')
                && $user->can('catalog.tariffs.create'),
            'can_return' => $user->can('pharmacy.return'),
            // ADR-072 — viewing the Soins consumable circuit is separate from
            // serving it: a pharmacist without exit rights still sees usage.
            'can_view_care_consumables' => $user->can('care_consumables.view'),
            'can_serve_care_consumables' => $user->can('care_consumables.serve'),
            'can_view_purchase_orders' => $user->can('purchase_orders.view'),
        ];
    }

    /** @return array<string, mixed> */
    public function dashboard(User $user): array
    {
        $capabilities = $this->capabilities($user);

        return [
            'capabilities' => $capabilities,
            'stockSummary' => $capabilities['can_view_stock'] ? $this->stockFor($capabilities)['summary'] : null,
            'dispenseCount' => $capabilities['can_view_prescriptions']
                ? $this->prescriptionQueue->activeDispenseCount()
                : 0,
            'careConsumableCount' => $capabilities['can_view_care_consumables']
                ? $this->careConsumables->openRequestCount()
                : 0,
            'awaitingStockCount' => $this->awaitingStockCount($capabilities),
            'alerts' => $this->alertsFor($capabilities),
        ];
    }

    /**
     * ADR-098 — « Médicaments & stock »: one list of the clinic's medicines
     * (family, sale price, delivery rule) with their stock when the account
     * may see it. An account with medicines.view only gets no quantity.
     *
     * @return array<string, mixed>
     */
    public function stock(User $user): array
    {
        $capabilities = $this->capabilities($user);
        $stock = $this->stockFor($capabilities);

        if (! $capabilities['can_view_stock']) {
            $stock['summary'] = array_map(fn () => null, $stock['summary']);
            $stock['medicines'] = collect($stock['medicines'])
                ->map(fn (array $medicine): array => [
                    ...$medicine,
                    'quantity_on_hand' => null,
                    'reserved_quantity' => null,
                    'available_quantity' => null,
                    'nearest_expiration' => null,
                    'status' => null,
                    'status_label' => null,
                    'lots' => [],
                ])
                ->all();
        }

        return [
            'capabilities' => $capabilities,
            'stock' => $stock,
            // ADR-182 — ce qui est réceptionné mais pas encore rangé : c'est ce
            // que l'écran « Entrée en stock » attend, et rien d'autre.
            'awaitingStockCount' => $this->awaitingStockCount($capabilities),
            'alerts' => $this->alertsFor($capabilities),
            // Archived families included, so they can be restored from the same panel.
            'categories' => $capabilities['can_view_categories']
                ? app(MedicineCatalogPresenter::class)->categories()
                : [],
        ];
    }

    /**
     * ADR-098 — the counting sheet: every lot with what the system holds
     * and what is reserved, so the pharmacist writes down what is on the shelf.
     */
    public function inventorySheet(User $user): array
    {
        $capabilities = $this->capabilities($user);

        return [
            'capabilities' => $capabilities,
            'categories' => MedicineCategory::query()->orderBy('name')->pluck('name'),
            'lots' => collect($this->stockFor($capabilities)['medicines'])
                ->flatMap(fn (array $medicine) => collect($medicine['lots'])->map(fn (array $lot): array => [
                    'uuid' => $lot['uuid'],
                    'lot_number' => $lot['lot_number'],
                    'expires_at' => $lot['expires_at'],
                    'status' => $lot['status'],
                    'status_label' => $lot['status_label'],
                    'quantity_on_hand' => $lot['quantity_on_hand'],
                    'reserved_quantity' => $lot['reserved_quantity'],
                    'medicine_uuid' => $medicine['uuid'],
                    'medicine_name' => $medicine['name'],
                    'medicine_code' => $medicine['code'],
                    'unit' => $medicine['unit'],
                    'category' => $medicine['category']['name'] ?? null,
                ]))
                ->sortBy([['medicine_name', 'asc'], ['expires_at', 'asc']])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function stockMedicine(User $user, Medicine $medicine): array
    {
        $capabilities = $this->capabilities($user);
        $row = collect($this->stockFor($capabilities)['medicines'])->firstWhere('uuid', $medicine->uuid);

        abort_if($row === null, 404);

        return ['capabilities' => $capabilities, 'medicine' => $row];
    }

    /**
     * ADR-182 — l'écran d'entrée en stock ne déroule plus le catalogue : ce
     * qui entre au stock vient d'une livraison réceptionnée, et c'est la file
     * des réceptions (ReceivedStockQueue) qui la sert. Il ne reste ici que ce
     * que le compte a le droit de faire en rangeant.
     *
     * @return array<string, mixed>
     */
    public function stockEntryForm(User $user): array
    {
        return ['capabilities' => $this->capabilities($user)];
    }

    /** @return array<string, mixed> */
    public function stockAdjustmentForm(User $user): array
    {
        $capabilities = $this->capabilities($user);

        return [
            'capabilities' => $capabilities,
            'lots' => collect($this->stockFor($capabilities)['medicines'])
                ->flatMap(fn (array $medicine) => collect($medicine['lots'])->map(fn (array $lot): array => [
                    ...$lot,
                    'medicine_uuid' => $medicine['uuid'],
                    'medicine_name' => $medicine['name'],
                    'unit' => $medicine['unit'],
                ]))
                ->values()
                ->all(),
            'adjustmentTypes' => collect(PharmacyStockAdjustmentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function dispenses(User $user): array
    {
        $capabilities = $this->capabilities($user);

        return [
            'capabilities' => $capabilities,
            'queue' => $this->prescriptionQueue->queue(
                $capabilities['can_view_lots'],
                $capabilities['can_view_expiration'],
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function careConsumables(User $user): array
    {
        return [
            'capabilities' => $this->capabilities($user),
            'careConsumables' => $this->careConsumables->pharmacyQueue(),
        ];
    }

    /** @return array<string, mixed> */

    /** @return array<string, mixed> */
    public function medicineForm(User $user): array
    {
        $capabilities = $this->capabilities($user);

        return [
            'capabilities' => $capabilities,
            'categories' => MedicineCategory::query()->orderBy('name')->get(['uuid', 'code', 'name']),
            'suppliers' => $capabilities['can_view_suppliers']
                ? MedicineSupplier::query()->orderBy('name')->get(['uuid', 'code', 'name'])
                : [],
            'medicineForms' => collect(MedicineForm::cases())->map(fn ($form) => [
                'value' => $form->value,
                'label' => $form->label(),
            ]),
        ];
    }

    /**
     * ADR-175, ADR-182 — les lignes réceptionnées qui attendent d'être
     * rangées. Seul un compte qui peut les faire entrer au stock les compte.
     *
     * @param  array<string, bool>  $capabilities
     */
    private function awaitingStockCount(array $capabilities): int
    {
        return $capabilities['can_record_entry'] ? $this->receivedStock->count() : 0;
    }

    /**
     * @param  array<string, bool>  $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function alertsFor(array $capabilities): array
    {
        if (! $capabilities['can_view_alerts']) {
            return [];
        }

        return PharmacyStockAlert::query()
            ->where('active_key', 'OPEN')
            ->with('medicine.catalogItem:id,name,code')
            ->latest('triggered_at')
            ->get()
            ->map(fn (PharmacyStockAlert $alert) => [
                'uuid' => $alert->uuid,
                'type' => $alert->type->value,
                'type_label' => $alert->type->label(),
                'medicine_uuid' => $alert->medicine->uuid,
                'medicine_name' => $alert->medicine->catalogItem?->name,
                'medicine_code' => $alert->medicine->catalogItem?->code,
                'available_quantity' => $alert->available_quantity,
                'threshold' => $alert->threshold,
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, bool> $capabilities */
    private function stockFor(array $capabilities): array
    {
        $stock = $this->stockOverview->overview();

        if (! $capabilities['can_view_lots']) {
            $stock['medicines'] = collect($stock['medicines'])
                ->map(fn (array $medicine): array => [...$medicine, 'lots' => []])
                ->all();
        }

        if (! $capabilities['can_view_expiration']) {
            $stock['summary']['expiring_soon'] = 0;
            $stock['summary']['expired_lots'] = 0;
            $stock['medicines'] = collect($stock['medicines'])
                ->map(function (array $medicine): array {
                    $medicine['nearest_expiration'] = null;
                    $medicine['status'] = $medicine['status'] === 'EXPIRING_SOON'
                        ? 'AVAILABLE'
                        : $medicine['status'];
                    $medicine['lots'] = collect($medicine['lots'])
                        ->map(fn (array $lot): array => [
                            ...$lot,
                            'received_at' => null,
                            'expires_at' => null,
                            'status' => $lot['status'] === 'EXPIRED' ? 'UNAVAILABLE' : 'AVAILABLE',
                        ])
                        ->all();

                    return $medicine;
                })
                ->all();
        }

        // Labels are resolved once, after any permission-driven masking, so
        // no screen ever carries its own copy of the wording.
        $stock['medicines'] = collect($stock['medicines'])
            ->map(fn (array $medicine): array => [
                ...$medicine,
                'status_label' => self::statusLabel($medicine['status']),
                'lots' => collect($medicine['lots'])
                    ->map(fn (array $lot): array => [...$lot, 'status_label' => self::statusLabel($lot['status'])])
                    ->all(),
            ])
            ->all();

        return $stock;
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'AVAILABLE' => 'Disponible',
            'OUT_OF_STOCK' => 'Rupture',
            'NEVER_RECEIVED' => 'Jamais reçu',
            'EXPIRING_SOON' => 'Péremption proche',
            'EXPIRED' => 'Périmé',
            'INACTIVE' => 'Inactif',
            default => 'Indisponible',
        };
    }
}
