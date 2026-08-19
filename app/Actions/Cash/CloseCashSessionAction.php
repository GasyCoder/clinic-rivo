<?php

namespace App\Actions\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashSession;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseCashSessionAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(string $actualClosingAmount, ?string $notes, User $actor): CashSession
    {
        return DB::transaction(function () use ($actualClosingAmount, $notes, $actor) {
            $session = CashSession::query()
                ->where('active_key', 'SINGLE_OPEN_CASH')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Aucune session de caisse n’est ouverte.',
                ]);
            }

            $cashMovementsMinor = $session->movements()
                ->where('affects_cash_balance', true)
                ->get(['direction', 'amount'])
                ->sum(fn ($movement) => $movement->direction === 'OUT'
                    ? -Money::toMinor($movement->amount)
                    : Money::toMinor($movement->amount));

            $expectedMinor = Money::toMinor($session->opening_amount) + $cashMovementsMinor;
            $actualMinor = Money::toMinor($actualClosingAmount);

            $session->fill([
                'active_key' => null,
                'status' => CashSessionStatus::Closed,
                'expected_closing_amount' => Money::fromMinor($expectedMinor),
                'actual_closing_amount' => Money::fromMinor($actualMinor),
                'variance_amount' => Money::fromMinor($actualMinor - $expectedMinor),
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'notes' => $notes ?: $session->notes,
            ])->save();

            $this->auditor->record(
                'cash.close',
                entity: $session,
                newValues: [
                    'expected_closing_amount' => $session->expected_closing_amount,
                    'actual_closing_amount' => $session->actual_closing_amount,
                    'variance_amount' => $session->variance_amount,
                ],
                module: 'cash',
                actor: $actor,
            );

            return $session->refresh();
        });
    }
}
