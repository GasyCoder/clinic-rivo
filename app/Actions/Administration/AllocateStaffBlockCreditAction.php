<?php

namespace App\Actions\Administration;

use App\Models\Employee;
use App\Models\StaffBlockCreditMovement;
use App\Models\User;
use App\Services\Finance\StaffBlockCreditLedger;
use Illuminate\Auth\Access\AuthorizationException;

class AllocateStaffBlockCreditAction
{
    public function __construct(private readonly StaffBlockCreditLedger $ledger) {}

    public function execute(
        Employee $employee,
        int|string $amount,
        string $idempotencyKey,
        string $reason,
        User $actor,
    ): StaffBlockCreditMovement {
        if ($actor->cannot('staff_block_credits.allocate')) {
            throw new AuthorizationException('Vous ne pouvez pas allouer un crédit forfaitaire Bloc.');
        }

        return $this->ledger->allocate($employee, $amount, $idempotencyKey, $reason, $actor);
    }
}
