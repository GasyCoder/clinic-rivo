<?php

namespace App\Services\Finance;

use App\DTOs\Billing\StaffFinancialAllocation;
use App\Enums\StaffCoveragePolicy;
use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class StaffFinancialAllocationService
{
    public function __construct(private readonly StaffBlockCreditLedger $ledger) {}

    public function preview(StaffCoveragePolicy $policy, int $grossMinor): StaffFinancialAllocation
    {
        return match ($policy) {
            StaffCoveragePolicy::OrdinaryFullCoverage => new StaffFinancialAllocation(
                $policy, $grossMinor, $grossMinor, 0, 0,
            ),
            StaffCoveragePolicy::NotCovered => new StaffFinancialAllocation(
                $policy, $grossMinor, 0, 0, $grossMinor,
            ),
            StaffCoveragePolicy::BlockCredit,
            StaffCoveragePolicy::Unclassified => new StaffFinancialAllocation(
                $policy, $grossMinor, null, null, null,
            ),
        };
    }

    /**
     * Read-only simulation for a BLOCK_CREDIT line.
     *
     * The caller supplies the currently available credit and remains
     * responsible for decrementing its in-memory preview across multiple
     * lines. No ledger row is written here; the real consumption remains in
     * allocate(), when a BillableItem is actually created.
     */
    public function previewWithAvailableBlockCredit(
        StaffCoveragePolicy $policy,
        int $grossMinor,
        int $availableBlockCreditMinor,
    ): StaffFinancialAllocation {
        $preview = $this->preview($policy, $grossMinor);

        if ($policy !== StaffCoveragePolicy::BlockCredit) {
            return $preview;
        }

        $usedMinor = min($grossMinor, max(0, $availableBlockCreditMinor));

        return new StaffFinancialAllocation(
            $policy,
            $grossMinor,
            $usedMinor,
            $usedMinor,
            $grossMinor - $usedMinor,
        );
    }

    public function allocate(
        Episode $episode,
        StaffCoveragePolicy $policy,
        BillableItem $billableItem,
        int $grossMinor,
        User $actor,
    ): StaffFinancialAllocation {
        $preview = $this->preview($policy, $grossMinor);

        if ($policy === StaffCoveragePolicy::Unclassified) {
            throw ValidationException::withMessages([
                'catalog_item_uuid' => 'La politique Personnel de cette prestation doit être classifiée avant facturation.',
            ]);
        }

        if ($preview->isResolved()) {
            return $preview;
        }

        $episode->loadMissing('staffCoverage.employee');
        $employee = $episode->staffCoverage?->employee;

        if (! $employee) {
            throw ValidationException::withMessages([
                'financial_mode' => 'Le passage Personnel ne référence aucun dossier Employé exploitable.',
            ]);
        }

        $movement = $this->ledger->consume($employee, $episode, $billableItem, $grossMinor, $actor);
        $usedMinor = $movement ? abs(Money::toMinor($movement->amount)) : 0;

        return new StaffFinancialAllocation(
            $policy,
            $grossMinor,
            $usedMinor,
            $usedMinor,
            $grossMinor - $usedMinor,
        );
    }
}
