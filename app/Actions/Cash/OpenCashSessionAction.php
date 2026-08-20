<?php

namespace App\Actions\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashSession;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Finance\FinancialNumberGenerator;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenCashSessionAction
{
    public function __construct(
        private readonly FinancialNumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    public function execute(string $openingAmount, ?string $notes, User $actor): CashSession
    {
        return DB::transaction(function () use ($openingAmount, $notes, $actor) {
            if (CashSession::query()->where('active_key', 'SINGLE_OPEN_CASH')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Une session de caisse est déjà ouverte sur ce site.',
                ]);
            }

            $session = CashSession::create([
                'session_number' => $this->numbers->cashSession(),
                'active_key' => 'SINGLE_OPEN_CASH',
                'status' => CashSessionStatus::Open,
                'opening_amount' => Money::fromMinor(Money::toMinor($openingAmount)),
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'notes' => $notes,
            ]);

            $this->auditor->record(
                'cash.open',
                entity: $session,
                newValues: ['opening_amount' => $session->opening_amount],
                module: 'cash',
                actor: $actor,
            );

            return $session;
        });
    }
}
