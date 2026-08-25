<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeServiceRequest;
use App\Models\User;
use App\Services\Billing\CatalogTariffResolver;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared domain entry point for future Medicine/Laboratory/Pharmacy/
 * Surgery/Care modules. Their own authorized Actions call this service;
 * no business module receives a payment capability through it.
 */
class RecordBillableItemAction
{
    public function __construct(private readonly CatalogTariffResolver $tariffs) {}

    /**
     * @param  array{catalog_item_uuid: string, quantity: int|string, payment_required_before_fulfillment?: bool}  $data
     */
    public function execute(Episode $episode, array $data, User $actor, ?Model $source = null): BillableItem
    {
        return DB::transaction(function () use ($episode, $data, $actor, $source) {
            $episode->loadMissing('patient');
            $this->tariffs->assertPatientCanBeBilled($episode->patient);
            $item = CatalogItem::query()
                ->where('uuid', $data['catalog_item_uuid'] ?? '')
                ->where('billable', true)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'La prestation sélectionnée est indisponible ou archivée.',
                ]);
            }

            $plannedRequest = EpisodeServiceRequest::query()
                ->where('episode_id', $episode->getKey())
                ->where('catalog_item_id', $item->getKey())
                ->lockForUpdate()
                ->first();

            $tariff = $plannedRequest?->catalog_tariff_id
                ? CatalogTariff::query()->lockForUpdate()->find($plannedRequest->catalog_tariff_id)
                : $this->tariffs->current($item, $episode->patient, lockForUpdate: true);

            if (! $tariff) {
                $category = $plannedRequest?->tariff_category
                    ?? $this->tariffs->categoryFor($episode->patient);

                throw ValidationException::withMessages([
                    'catalog_item_uuid' => "Le tarif {$category->label()} de cette prestation n’est pas configuré.",
                ]);
            }

            $quantityMinor = Money::toMinor($data['quantity']);
            $unitPrice = $plannedRequest?->unit_price ?? $tariff->amount;
            $unitPriceMinor = Money::toMinor($unitPrice);
            $totalMinor = Money::multiply($data['quantity'], $unitPrice);

            if ($quantityMinor <= 0 || $unitPriceMinor <= 0 || $totalMinor > 999_999_999_999_999) {
                throw ValidationException::withMessages([
                    'amount' => 'La quantité et le tarif doivent produire un montant valide supérieur à zéro.',
                ]);
            }

            $coverage = $plannedRequest?->coverage_rate !== null
                ? [
                    'organization_uuid' => $plannedRequest->mutual_organization_uuid,
                    'organization_name' => $plannedRequest->mutual_organization_name,
                    'coverage_rate' => $plannedRequest->coverage_rate,
                ]
                : $this->tariffs->coverageSnapshot($episode->patient);
            $coverageMinor = Money::percentage($totalMinor, $coverage['coverage_rate'] ?? '0.00');

            return BillableItem::create([
                'episode_id' => $episode->id,
                'source_module' => $item->module->value,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'source_uuid' => $source?->getAttribute('uuid'),
                'catalog_item_id' => $item->id,
                'catalog_tariff_id' => $tariff->id,
                'tariff_category' => $plannedRequest?->tariff_category ?? $tariff->tariff_category,
                'mutual_organization_uuid' => $coverage['organization_uuid'],
                'mutual_organization_name' => $coverage['organization_name'],
                'coverage_rate' => $coverage['coverage_rate'] ?? '0.00',
                // Snapshot obligatoire : les changements de tarif futurs ne
                // modifient jamais une prestation/facture déjà créée.
                'description' => $item->name,
                'quantity' => Money::normalize($data['quantity']),
                'unit_price' => Money::fromMinor($unitPriceMinor),
                'total_amount' => Money::fromMinor($totalMinor),
                'gross_amount' => Money::fromMinor($totalMinor),
                'coverage_amount' => Money::fromMinor($coverageMinor),
                'patient_amount' => Money::fromMinor($totalMinor - $coverageMinor),
                'currency' => $tariff->currency,
                'payment_required_before_fulfillment' => (bool) ($data['payment_required_before_fulfillment'] ?? false),
                'status' => BillableItemStatus::Pending,
                'created_by' => $actor->id,
            ]);
        });
    }
}
