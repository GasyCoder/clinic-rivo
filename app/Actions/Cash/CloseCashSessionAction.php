<?php

namespace App\Actions\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseCashSessionAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(
        string $actualClosingAmount,
        ?string $notes,
        User|CatalogActor $actor,
        ?string $closingReason = null,
        ?CashRegister $register = null,
    ): CashSession {
        return DB::transaction(function () use ($actualClosingAmount, $notes, $actor, $closingReason, $register) {
            $session = CashSession::query()
                ->where('active_key', CashSession::activeKeyFor($register))
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Aucune session de caisse n’est ouverte.',
                ]);
            }

            // Only the person who opened it may close it locally — central
            // supervision (CatalogActor) is the one deliberate override, and
            // the only way to end a stuck till's custody at all: no local or
            // central action may hand a still-open session to someone else
            // without a real cash count first.
            if ($actor instanceof User && $session->opened_by !== $actor->id) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Cette caisse est utilisée par une autre personne. Utilisez une autre caisse disponible.',
                ]);
            }

            if ($session->status === CashSessionStatus::Locked && $actor instanceof User) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Cette session est verrouillée par la supervision. Déverrouillez-la avant une clôture locale.',
                ]);
            }

            $cashActor = $actor instanceof User ? CatalogActor::fromUser($actor) : $actor;

            $expectedMinor = Money::toMinor($session->computeExpectedClosingAmount());
            $actualMinor = Money::toMinor($actualClosingAmount);

            $session->fill([
                'active_key' => null,
                'status' => CashSessionStatus::Closed,
                'expected_closing_amount' => Money::fromMinor($expectedMinor),
                'actual_closing_amount' => Money::fromMinor($actualMinor),
                'variance_amount' => Money::fromMinor($actualMinor - $expectedMinor),
                'closed_by' => $cashActor->localUserId(),
                ...$cashActor->externalAttribution('closed'),
                'closed_at' => now(),
                'closing_reason' => $closingReason,
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
                reason: $closingReason,
                module: 'cash',
                actor: $cashActor->user(),
            );

            return $session->refresh();
        });
    }
}
