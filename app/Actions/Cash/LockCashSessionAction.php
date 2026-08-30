<?php

namespace App\Actions\Cash;

use App\Enums\CashSessionStatus;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Services\Audit\Auditor;
use App\Services\Catalog\CatalogActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LockCashSessionAction
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
                    'cash_session' => 'Cette caisse ne possède aucune session ouverte à verrouiller.',
                ]);
            }

            if ($session->status === CashSessionStatus::Locked) {
                throw ValidationException::withMessages([
                    'cash_session' => 'Cette session est déjà verrouillée.',
                ]);
            }

            $session->fill([
                'status' => CashSessionStatus::Locked,
                'locked_by' => $actor->localUserId(),
                ...$actor->externalAttribution('locked'),
                'locked_at' => now(),
                'lock_reason' => $reason,
                'unlocked_by' => null,
                'external_unlocked_by_uuid' => null,
                'external_unlocked_by_name' => null,
                'unlocked_at' => null,
            ])->save();

            $this->auditor->record(
                'cash.lock',
                entity: $session,
                newValues: ['status' => CashSessionStatus::Locked->value],
                reason: $reason,
                module: 'cash',
                actor: $actor->user(),
            );

            return $session->refresh();
        });
    }
}
