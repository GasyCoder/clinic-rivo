<?php

namespace App\DTOs\Billing;

use App\Enums\StaffCoveragePolicy;

final readonly class StaffFinancialAllocation
{
    public function __construct(
        public StaffCoveragePolicy $policy,
        public int $grossMinor,
        public ?int $staffCoveredMinor,
        public ?int $blockCreditUsedMinor,
        public ?int $patientMinor,
    ) {}

    public function isResolved(): bool
    {
        return $this->staffCoveredMinor !== null
            && $this->blockCreditUsedMinor !== null
            && $this->patientMinor !== null;
    }
}
