<?php

namespace App\Services\Reception;

use App\Enums\CatalogItemType;
use App\Enums\EpisodeFinancialMode;
use App\Enums\StaffCoveragePolicy;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Services\Billing\CatalogTariffResolver;
use App\Services\Finance\StaffBlockCreditLedger;
use App\Services\Finance\StaffFinancialAllocationService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Read-only financial projection after an Episode context has been chosen.
 *
 * This service never creates a clinical request, orientation, billable item,
 * invoice, payment or StaffBlockCreditMovement. It only resolves current
 * server-owned tariffs and simulates the expected split for confirmation.
 */
class ReceptionFinancialPreviewService
{
    public function __construct(
        private readonly CatalogTariffResolver $tariffs,
        private readonly StaffFinancialAllocationService $staffFinancials,
        private readonly StaffBlockCreditLedger $staffBlockCredits,
    ) {}

    /**
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $lines
     * @return array<string, mixed>
     */
    public function preview(Episode $episode, array $lines): array
    {
        if ($episode->financial_mode === null) {
            throw ValidationException::withMessages([
                'financial_mode' => 'Choisissez le mode de prise en charge de ce passage.',
            ]);
        }

        $normalized = $this->normalizeLines($lines);
        $episode->loadMissing(['mutualCoverage', 'staffCoverage.employee']);
        $category = $this->tariffs->categoryFor($episode);
        $coverage = $this->tariffs->coverageSnapshot($episode);
        $isStaff = $episode->financial_mode === EpisodeFinancialMode::Staff;

        $items = CatalogItem::query()
            ->whereIn('uuid', $normalized->keys())
            ->where('type', CatalogItemType::Service->value)
            ->where('billable', true)
            ->where('reception_selectable', true)
            ->whereNotNull('reception_routing_mode')
            ->get()
            ->keyBy('uuid');

        if ($items->count() !== $normalized->count()) {
            throw ValidationException::withMessages([
                'lines' => 'Une prestation est indisponible ou sans parcours Réception configuré.',
            ]);
        }

        $availableBlockMinor = 0;

        if ($isStaff) {
            $employee = $episode->staffCoverage?->employee;

            if (! $employee) {
                throw ValidationException::withMessages([
                    'financial_mode' => 'Le passage Personnel ne référence aucun dossier Employé exploitable.',
                ]);
            }

            $availableBlockMinor = Money::toMinor(
                $this->staffBlockCredits->summary($employee)['available'],
            );
        }

        $grossTotalMinor = 0;
        $coverageTotalMinor = 0;
        $patientTotalMinor = 0;
        $resolutionPending = false;

        $previewLines = $normalized->map(function (string $quantity, string $uuid) use (
            $items,
            $episode,
            $coverage,
            $isStaff,
            &$availableBlockMinor,
            &$grossTotalMinor,
            &$coverageTotalMinor,
            &$patientTotalMinor,
            &$resolutionPending,
        ): array {
            /** @var CatalogItem $item */
            $item = $items->get($uuid);
            $tariff = $this->tariffs->current($item, $episode);
            $grossMinor = $tariff ? Money::multiply($quantity, $tariff->amount) : null;
            $coveredMinor = null;
            $patientMinor = null;
            $blockCreditUsedMinor = null;

            if ($grossMinor === null) {
                $resolutionPending = true;
            } elseif ($isStaff) {
                $allocation = $this->staffFinancials->previewWithAvailableBlockCredit(
                    $item->staff_coverage_policy,
                    $grossMinor,
                    $availableBlockMinor,
                );

                if ($allocation->isResolved()) {
                    $coveredMinor = $allocation->staffCoveredMinor;
                    $patientMinor = $allocation->patientMinor;
                    $blockCreditUsedMinor = $allocation->blockCreditUsedMinor;

                    if ($item->staff_coverage_policy === StaffCoveragePolicy::BlockCredit) {
                        $availableBlockMinor -= $blockCreditUsedMinor ?? 0;
                    }
                } else {
                    $resolutionPending = true;
                }
            } else {
                $coveredMinor = Money::percentage($grossMinor, $coverage['coverage_rate'] ?? '0.00');
                $patientMinor = $grossMinor - $coveredMinor;
            }

            if ($grossMinor !== null) {
                $grossTotalMinor += $grossMinor;
            }

            if ($coveredMinor !== null && $patientMinor !== null) {
                $coverageTotalMinor += $coveredMinor;
                $patientTotalMinor += $patientMinor;
            }

            return [
                'catalog_item_uuid' => $item->uuid,
                'code' => $item->code,
                'name' => $item->name,
                'module' => $item->module->value,
                'module_label' => $item->module->label(),
                'routing_mode' => $item->reception_routing_mode->value,
                'routing_label' => $item->reception_routing_mode->label(),
                'quantity' => $quantity,
                'unit' => $item->unit,
                'unit_price' => $tariff?->amount,
                'gross_amount' => $grossMinor !== null ? Money::fromMinor($grossMinor) : null,
                'coverage_amount' => $coveredMinor !== null ? Money::fromMinor($coveredMinor) : null,
                'staff_block_credit_used' => $blockCreditUsedMinor !== null
                    ? Money::fromMinor($blockCreditUsedMinor)
                    : null,
                'patient_amount' => $patientMinor !== null ? Money::fromMinor($patientMinor) : null,
                'staff_coverage_policy' => $isStaff ? $item->staff_coverage_policy->value : null,
                'financial_resolution_pending' => $grossMinor === null
                    || $coveredMinor === null
                    || $patientMinor === null,
                'currency' => $tariff?->currency ?? 'MGA',
            ];
        })->values();

        return [
            'financial_mode' => $episode->financial_mode->value,
            'financial_mode_label' => $episode->financial_mode->label(),
            'tariff_category' => $category->value,
            'organization_name' => $coverage['organization_name'],
            'coverage_rate' => $coverage['coverage_rate'],
            'lines' => $previewLines->all(),
            'totals' => [
                'gross_amount' => $previewLines->contains(fn (array $line) => $line['gross_amount'] === null)
                    ? null
                    : Money::fromMinor($grossTotalMinor),
                'coverage_amount' => $resolutionPending ? null : Money::fromMinor($coverageTotalMinor),
                'patient_amount' => $resolutionPending ? null : Money::fromMinor($patientTotalMinor),
                'currency' => 'MGA',
                'resolution_pending' => $resolutionPending,
            ],
            'initial_destination' => $this->initialDestination($items->values()),
        ];
    }

    /**
     * @param  array<int, array{catalog_item_uuid: string, quantity: int|string}>  $lines
     * @return Collection<string, string>
     */
    private function normalizeLines(array $lines): Collection
    {
        if ($lines === [] || count($lines) > 50) {
            throw ValidationException::withMessages([
                'lines' => 'Sélectionnez entre une et cinquante prestations.',
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

        return $normalized;
    }

    /** @param Collection<int, CatalogItem> $items */
    private function initialDestination(Collection $items): ?array
    {
        if ($items->contains(fn (CatalogItem $item) => $item->reception_routing_mode->startsWithCare())) {
            return ['module' => 'CARE', 'label' => 'Soins'];
        }

        if ($items->contains(fn (CatalogItem $item) => $item->reception_routing_mode->requiresMedicine())) {
            return ['module' => 'MEDICINE', 'label' => 'Médecine'];
        }

        return null;
    }
}
