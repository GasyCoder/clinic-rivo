<?php

namespace App\Actions\Billing;

use App\Enums\BillableItemStatus;
use App\Enums\EpisodeFinancialMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\CatalogTariff;
use App\Models\Episode;
use App\Models\EpisodeServiceRequest;
use App\Models\User;
use App\Services\Billing\CatalogTariffResolver;
use App\Services\Finance\StaffFinancialAllocationService;
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
    public function __construct(
        private readonly CatalogTariffResolver $tariffs,
        private readonly StaffFinancialAllocationService $staffFinancials,
    ) {}

    /**
     * @param  array{catalog_item_uuid: string, quantity: int|string, payment_required_before_fulfillment?: bool, idempotency_key?: string}  $data
     */
    public function execute(Episode $episode, array $data, User $actor, ?Model $source = null): BillableItem
    {
        return DB::transaction(function () use ($episode, $data, $actor, $source) {
            $episode = Episode::query()
                ->with(['mutualCoverage', 'staffCoverage.employee'])
                ->lockForUpdate()
                ->findOrFail($episode->getKey());
            $this->tariffs->assertEpisodeCanBeBilled($episode);
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

            $effectiveSource = $source ?? $plannedRequest;
            $idempotencyKey = filled($data['idempotency_key'] ?? null)
                ? trim((string) $data['idempotency_key'])
                : $this->idempotencyKeyFor($effectiveSource, $item);

            if ($idempotencyKey !== null && mb_strlen($idempotencyKey) > 191) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clé d’idempotence de la prestation dépasse la longueur autorisée.',
                ]);
            }

            if ($idempotencyKey) {
                $existing = BillableItem::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $this->assertIdempotentReplayMatches($existing, $episode, $item, $data['quantity']);

                    return $existing;
                }
            }

            $tariff = $plannedRequest?->catalog_tariff_id
                ? CatalogTariff::query()->lockForUpdate()->find($plannedRequest->catalog_tariff_id)
                : $this->tariffs->current($item, $episode, lockForUpdate: true);

            if (! $tariff) {
                $category = $plannedRequest?->tariff_category
                    ?? $this->tariffs->categoryFor($episode);

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

            $staffPolicy = $plannedRequest?->staff_coverage_policy ?? $item->staff_coverage_policy;
            $isStaff = $episode->financial_mode === EpisodeFinancialMode::Staff;

            if ($isStaff && $staffPolicy === StaffCoveragePolicy::Unclassified) {
                throw ValidationException::withMessages([
                    'catalog_item_uuid' => 'La politique Personnel de cette prestation doit être classifiée avant facturation.',
                    'financial_mode' => 'Le contexte Personnel reste financièrement en attente tant que cette prestation n’est pas classifiée.',
                ]);
            }

            if ($isStaff && $staffPolicy === StaffCoveragePolicy::BlockCredit && ! $idempotencyKey) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Une prestation Bloc exige une source financière idempotente avant de consommer le crédit.',
                ]);
            }

            $coverage = $plannedRequest?->coverage_rate !== null
                ? [
                    'organization_uuid' => $plannedRequest->mutual_organization_uuid,
                    'organization_name' => $plannedRequest->mutual_organization_name,
                    'coverage_rate' => $plannedRequest->coverage_rate,
                ]
                : $this->tariffs->coverageSnapshot($episode);
            $coverageMinor = $isStaff
                ? 0
                : Money::percentage($totalMinor, $coverage['coverage_rate'] ?? '0.00');

            $billableItem = BillableItem::create([
                'episode_id' => $episode->id,
                'source_module' => $item->module->value,
                'source_type' => $effectiveSource?->getMorphClass(),
                'source_id' => $effectiveSource?->getKey(),
                'source_uuid' => $effectiveSource?->getAttribute('uuid'),
                'idempotency_key' => $idempotencyKey,
                'catalog_item_id' => $item->id,
                'catalog_tariff_id' => $tariff->id,
                'tariff_category' => $plannedRequest?->tariff_category ?? $tariff->tariff_category,
                'staff_coverage_policy' => $staffPolicy,
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
                'staff_covered_amount' => '0.00',
                'staff_block_credit_used' => '0.00',
                'patient_amount' => Money::fromMinor($totalMinor - $coverageMinor),
                'currency' => $tariff->currency,
                'payment_required_before_fulfillment' => (bool) ($data['payment_required_before_fulfillment'] ?? false),
                'status' => BillableItemStatus::Pending,
                'created_by' => $actor->id,
            ]);

            if (! $isStaff) {
                return $billableItem;
            }

            $allocation = $this->staffFinancials->allocate(
                $episode,
                $staffPolicy,
                $billableItem,
                $totalMinor,
                $actor,
            );

            $billableItem->fill([
                'coverage_amount' => Money::fromMinor($allocation->staffCoveredMinor ?? 0),
                'staff_covered_amount' => Money::fromMinor($allocation->staffCoveredMinor ?? 0),
                'staff_block_credit_used' => Money::fromMinor($allocation->blockCreditUsedMinor ?? 0),
                'patient_amount' => Money::fromMinor($allocation->patientMinor ?? $totalMinor),
            ])->save();

            return $billableItem->refresh();
        });
    }

    private function idempotencyKeyFor(?Model $source, CatalogItem $item): ?string
    {
        if (! $source) {
            return null;
        }

        $sourceIdentity = $source->getAttribute('uuid') ?: $source->getKey();

        return 'billing:'.hash('sha256', implode('|', [
            $source->getMorphClass(),
            (string) $sourceIdentity,
            $item->uuid,
        ]));
    }

    private function assertIdempotentReplayMatches(
        BillableItem $existing,
        Episode $episode,
        CatalogItem $item,
        int|string $quantity,
    ): void {
        if ($existing->episode_id !== $episode->getKey()
            || $existing->catalog_item_id !== $item->getKey()
            || Money::toMinor($existing->quantity) !== Money::toMinor($quantity)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Cette clé d’idempotence correspond déjà à une autre prestation.',
            ]);
        }
    }
}
