<?php

namespace App\Actions\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashRegister;
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

    public function execute(string $openingAmount, ?string $notes, User $actor, ?string $cashRegisterUuid = null): CashSession
    {
        return DB::transaction(function () use ($openingAmount, $notes, $actor, $cashRegisterUuid) {
            $register = null;

            if ($cashRegisterUuid !== null) {
                $register = CashRegister::query()->where('uuid', $cashRegisterUuid)->where('active', true)
                    ->lockForUpdate()->first();

                if (! $register) {
                    throw ValidationException::withMessages([
                        'cash_register_uuid' => 'Cette caisse n’est plus disponible. Choisissez-en une autre.',
                    ]);
                }
            } elseif (CashRegister::query()->where('active', true)->exists()) {
                throw ValidationException::withMessages([
                    'cash_register_uuid' => 'Choisissez la caisse à ouvrir.',
                ]);
            }

            // Scoped to this register (or the site-wide sentinel when none is
            // configured) so a different register can open concurrently —
            // only the same register colliding with itself is refused.
            if (CashSession::query()->where('active_key', CashSession::activeKeyFor($register))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'cash_session' => $register
                        ? "La caisse « {$register->name} » est déjà ouverte."
                        : 'Une session de caisse est déjà ouverte sur ce site.',
                ]);
            }

            $session = CashSession::create([
                'session_number' => $this->numbers->cashSession(),
                'active_key' => CashSession::activeKeyFor($register),
                'cash_register_id' => $register?->id,
                'status' => CashSessionStatus::Open,
                'opening_amount' => Money::fromMinor(Money::toMinor($openingAmount)),
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'notes' => $notes,
            ]);

            $this->auditor->record(
                'cash.open',
                entity: $session,
                newValues: ['opening_amount' => $session->opening_amount, 'cash_register' => $register?->name],
                module: 'cash',
                actor: $actor,
            );

            return $session;
        });
    }
}
