<?php

namespace App\Services\Pharmacy;

use App\Enums\MedicineForm;
use App\Enums\PharmacyStockAdjustmentType;
use App\Models\MedicineCategory;
use App\Models\MedicineSupplier;
use App\Models\PharmacyStockAlert;
use App\Models\User;

class PharmacyWorkspaceService
{
    public function __construct(
        private readonly MedicineStockOverviewService $stockOverview,
        private readonly PharmacyPrescriptionQueueService $prescriptionQueue,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $canViewStock = $user->can('stock.view');

        $capabilities = [
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
            'can_create_counter_sale' => $user->can('pharmacy.counter_sales.create'),
            'can_adjust_stock' => $user->can('stock.adjust'),
            'can_view_alerts' => $user->can('stock.alerts.view'),
            'can_view_suppliers' => $user->can('medicine_suppliers.view'),
            'can_view_categories' => $user->can('medicine_categories.view'),
            'can_view_cost' => $user->can('stock.cost.view'),
            'can_record_cost' => $user->can('stock.cost.record'),
            'can_create_category' => $user->can('medicine_categories.create'),
            'can_create_supplier' => $user->can('medicine_suppliers.create'),
            'can_create_medicine' => $user->can('medicines.create')
                && $user->can('catalog.items.create')
                && $user->can('catalog.tariffs.create'),
            'can_import_medicines' => $user->can('medicines.import')
                && $user->can('medicines.create')
                && $user->can('catalog.items.create')
                && $user->can('catalog.tariffs.create'),
            'can_return' => $user->can('pharmacy.return'),
        ];

        $stock = $capabilities['can_view_stock']
            ? $this->stockFor($capabilities)
            : ['summary' => [], 'medicines' => []];

        $queue = $capabilities['can_view_prescriptions']
            ? $this->prescriptionQueue->queue(
                $capabilities['can_view_lots'],
                $capabilities['can_view_expiration'],
            )
            : ['summary' => [], 'prescriptions' => []];

        return [
            'capabilities' => $capabilities,
            'stock' => $stock,
            'queue' => $queue,
            'categories' => $capabilities['can_view_categories']
                ? MedicineCategory::query()->orderBy('name')->get(['uuid', 'code', 'name'])
                : [],
            'suppliers' => $capabilities['can_view_suppliers']
                ? MedicineSupplier::query()->orderBy('name')->get(['uuid', 'code', 'name', 'contact_name', 'phone'])
                : [],
            'alerts' => $capabilities['can_view_alerts']
                ? PharmacyStockAlert::query()
                    ->where('active_key', 'OPEN')
                    ->with('medicine.catalogItem:id,name,code')
                    ->latest('triggered_at')
                    ->get()
                    ->map(fn (PharmacyStockAlert $alert) => [
                        'uuid' => $alert->uuid,
                        'type' => $alert->type->value,
                        'type_label' => $alert->type->label(),
                        'medicine_name' => $alert->medicine->catalogItem?->name,
                        'medicine_code' => $alert->medicine->catalogItem?->code,
                        'available_quantity' => $alert->available_quantity,
                        'threshold' => $alert->threshold,
                        'triggered_at' => $alert->triggered_at?->toIso8601String(),
                    ])->values()
                : [],
            'adjustmentTypes' => collect(PharmacyStockAdjustmentType::cases())->map(fn ($type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
            'medicineForms' => collect(MedicineForm::cases())->map(fn ($form) => [
                'value' => $form->value,
                'label' => $form->label(),
            ]),
        ];
    }

    /** @return array{navigation: array<string, bool|int>, medicines: array<int, array<string, mixed>>} */
    public function externalCounterSaleFor(User $user): array
    {
        abort_unless($user->can('pharmacy.counter_sales.create'), 403);

        $canViewPrescriptions = $user->can('prescriptions.view');

        return [
            'navigation' => [
                'can_view_stock' => $user->can('stock.view'),
                'can_view_prescriptions' => $canViewPrescriptions,
                'can_view_categories' => $user->can('medicine_categories.view'),
                'can_view_suppliers' => $user->can('medicine_suppliers.view'),
                'dispense_count' => $canViewPrescriptions
                    ? $this->prescriptionQueue->activeDispenseCount()
                    : 0,
            ],
            'medicines' => collect($this->stockOverview->overview()['medicines'])
                ->filter(fn (array $medicine): bool => $medicine['active']
                    && $medicine['billable']
                    && filled($medicine['sale_price'])
                    && $medicine['available_quantity'] > 0)
                ->map(fn (array $medicine): array => [
                    'uuid' => $medicine['uuid'],
                    'code' => $medicine['code'],
                    'name' => $medicine['name'],
                    'generic_name' => $medicine['generic_name'],
                    'form_label' => $medicine['form_label'],
                    'strength' => $medicine['strength'],
                    'barcode' => $medicine['barcode'],
                    'category' => $medicine['category'] ? [
                        'uuid' => $medicine['category']['uuid'],
                        'name' => $medicine['category']['name'],
                    ] : null,
                    'unit' => $medicine['unit'],
                    'sale_price' => $medicine['sale_price'],
                    'prescription_required' => $medicine['prescription_required'],
                    'available_quantity' => $medicine['available_quantity'],
                ])
                ->values()
                ->all(),
        ];
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

        return $stock;
    }
}
