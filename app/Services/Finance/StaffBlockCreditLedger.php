<?php

namespace App\Services\Finance;

use App\Enums\StaffBlockCreditMovementType;
use App\Models\BillableItem;
use App\Models\Employee;
use App\Models\Episode;
use App\Models\StaffBlockCreditMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffBlockCreditLedger
{
    /** @return array{allocated: string, consumed: string, reversed: string, available: string} */
    public function summary(Employee $employee): array
    {
        $totals = StaffBlockCreditMovement::query()
            ->where('employee_id', $employee->getKey())
            ->selectRaw('movement_type, SUM(amount) as total')
            ->groupBy('movement_type')
            ->pluck('total', 'movement_type');

        $allocatedMinor = Money::toMinor((string) ($totals[StaffBlockCreditMovementType::Allocation->value] ?? '0'));
        $consumedMinor = abs(Money::toMinor((string) ($totals[StaffBlockCreditMovementType::Consumption->value] ?? '0')));
        $reversedMinor = Money::toMinor((string) ($totals[StaffBlockCreditMovementType::Reversal->value] ?? '0'));

        return [
            'allocated' => Money::fromMinor($allocatedMinor),
            'consumed' => Money::fromMinor($consumedMinor),
            'reversed' => Money::fromMinor($reversedMinor),
            'available' => Money::fromMinor(max(0, $allocatedMinor - $consumedMinor + $reversedMinor)),
        ];
    }

    public function allocate(
        Employee $employee,
        int|string $amount,
        string $idempotencyKey,
        string $reason,
        User $actor,
    ): StaffBlockCreditMovement {
        $amountMinor = Money::toMinor($amount);
        $idempotencyKey = trim($idempotencyKey);
        $reason = trim($reason);

        if ($amountMinor <= 0 || $amountMinor > 999_999_999_999_999) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant alloué doit être supérieur à zéro et rester dans la limite financière autorisée.',
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif de l’allocation est obligatoire.']);
        }

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Une clé d’idempotence valide est obligatoire pour l’allocation.',
            ]);
        }

        return DB::transaction(function () use ($employee, $amountMinor, $idempotencyKey, $reason, $actor) {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());
            $existing = StaffBlockCreditMovement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $this->assertReplayMatches(
                    $existing,
                    $employee->getKey(),
                    StaffBlockCreditMovementType::Allocation,
                    $amountMinor,
                );

                return $existing;
            }

            if (! $employee->active || $employee->trashed()) {
                throw ValidationException::withMessages([
                    'employee' => 'Le crédit Bloc ne peut être alloué qu’à un employé actif.',
                ]);
            }

            $beforeMinor = $this->currentBalanceMinor($employee->getKey());

            return StaffBlockCreditMovement::query()->create([
                'employee_id' => $employee->getKey(),
                'amount' => Money::fromMinor($amountMinor),
                'movement_type' => StaffBlockCreditMovementType::Allocation,
                'balance_before' => Money::fromMinor($beforeMinor),
                'balance_after' => Money::fromMinor($beforeMinor + $amountMinor),
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'created_by' => $actor->getKey(),
            ]);
        });
    }

    public function consume(
        Employee $employee,
        Episode $episode,
        BillableItem $billableItem,
        int $requestedMinor,
        User $actor,
    ): ?StaffBlockCreditMovement {
        if ($requestedMinor <= 0) {
            return null;
        }

        return DB::transaction(function () use ($employee, $episode, $billableItem, $requestedMinor, $actor) {
            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($employee->getKey());
            $idempotencyKey = 'staff-block-consumption:'.$billableItem->uuid;
            $existing = StaffBlockCreditMovement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ($existing->billable_item_id !== $billableItem->getKey()
                    || $existing->movement_type !== StaffBlockCreditMovementType::Consumption) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'La clé d’idempotence du crédit Bloc correspond à une autre opération.',
                    ]);
                }

                return $existing;
            }

            if (! $employee->active || $employee->trashed()) {
                throw ValidationException::withMessages([
                    'financial_mode' => 'Le crédit Bloc ne peut pas être consommé pour un dossier Employé inactif ou archivé.',
                ]);
            }

            $beforeMinor = $this->currentBalanceMinor($employee->getKey());
            $usedMinor = min($requestedMinor, max(0, $beforeMinor));

            if ($usedMinor === 0) {
                return null;
            }

            return StaffBlockCreditMovement::query()->create([
                'employee_id' => $employee->getKey(),
                'amount' => Money::fromMinor(-$usedMinor),
                'movement_type' => StaffBlockCreditMovementType::Consumption,
                'episode_id' => $episode->getKey(),
                'billable_item_id' => $billableItem->getKey(),
                'balance_before' => Money::fromMinor($beforeMinor),
                'balance_after' => Money::fromMinor($beforeMinor - $usedMinor),
                'idempotency_key' => $idempotencyKey,
                'reason' => "Consommation du forfait Bloc — prestation {$billableItem->uuid}",
                'created_by' => $actor->getKey(),
            ]);
        });
    }

    public function reverseConsumption(
        BillableItem $billableItem,
        string $reason,
        User $actor,
    ): ?StaffBlockCreditMovement {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Le motif de la réversion est obligatoire.',
            ]);
        }

        return DB::transaction(function () use ($billableItem, $reason, $actor) {
            $consumption = StaffBlockCreditMovement::query()
                ->where('billable_item_id', $billableItem->getKey())
                ->where('movement_type', StaffBlockCreditMovementType::Consumption->value)
                ->lockForUpdate()
                ->first();

            if (! $consumption) {
                return null;
            }

            $employee = Employee::withTrashed()->lockForUpdate()->findOrFail($consumption->employee_id);
            $idempotencyKey = 'staff-block-reversal:'.$billableItem->uuid;
            $existing = StaffBlockCreditMovement::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }

            $beforeMinor = $this->currentBalanceMinor($employee->getKey());
            $reversedMinor = abs(Money::toMinor($consumption->amount));

            return StaffBlockCreditMovement::query()->create([
                'employee_id' => $employee->getKey(),
                'amount' => Money::fromMinor($reversedMinor),
                'movement_type' => StaffBlockCreditMovementType::Reversal,
                'episode_id' => $consumption->episode_id,
                'billable_item_id' => $billableItem->getKey(),
                'reversal_of_id' => $consumption->getKey(),
                'balance_before' => Money::fromMinor($beforeMinor),
                'balance_after' => Money::fromMinor($beforeMinor + $reversedMinor),
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'created_by' => $actor->getKey(),
            ]);
        });
    }

    private function currentBalanceMinor(int $employeeId): int
    {
        $balance = StaffBlockCreditMovement::query()
            ->where('employee_id', $employeeId)
            ->latest('id')
            ->value('balance_after');

        return $balance === null ? 0 : Money::toMinor((string) $balance);
    }

    private function assertReplayMatches(
        StaffBlockCreditMovement $movement,
        int $employeeId,
        StaffBlockCreditMovementType $type,
        int $amountMinor,
    ): void {
        if ($movement->employee_id !== $employeeId
            || $movement->movement_type !== $type
            || Money::toMinor($movement->amount) !== $amountMinor) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Cette clé d’idempotence a déjà été utilisée pour une autre opération.',
            ]);
        }
    }
}
