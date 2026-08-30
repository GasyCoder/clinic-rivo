<?php

namespace App\Actions\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnlockCashSessionAction
{
    public function __construct(private readonly Auditor $auditor) {}

    public function execute(CashRegister $register, string $reason, CatalogActor $actor): CashSession
    {
        return DB::transaction(function () use ($register, $reason, $actor): CashSession {
            $session = CashSession::query()
                ->where('active_key', CashSession::activeKeyFor($register))
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Cette caisse ne possède aucune session à déverrouiller.',
                ]);
            }

            if ($session->status !== CashSessionStatus::Locked) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Cette session n’est pas verrouillée.',
                ]);
            }

            $session->fill([
                'status' => CashSessionStatus::Open,
                'unlocked_by' => $actor->localUserId(),
                ...$actor->externalAttribution('unlocked'),
                'unlocked_at' => now(),
            ])->save();

            $this->auditor->record(
                'cash.unlock',
                entity: $session,
                newValues: ['status' => CashSessionStatus::Open->value],
                oldValues: ['status' => CashSessionStatus::Locked->value],
                reason: $reason,
                module: 'cash',
                actor: $actor->user(),
            );

            return $session->refresh();
        });
    }
}
