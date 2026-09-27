<?php

namespace App\Actions\Bonus;

use App\Enums\BonusAwardStatus;
use App\Models\BonusAward;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-212 — la suite d'un bonus validé : versé (hors RIVO, ADR-066/206 : rien
 * n'est payé ni calculé ici, on trace le versement) ou annulé avec un motif.
 * Un bonus versé ne revient plus en arrière ; un bonus annulé libère sa place
 * pour ce mois, sans rien effacer.
 */
class SettleBonusAwardAction
{
    public function pay(BonusAward $award, ?string $note, User $actor): BonusAward
    {
        if ($actor->cannot('bonus_awards.pay')) {
            throw new AuthorizationException('Vous ne pouvez pas marquer un bonus versé.');
        }

        return $this->onValidated($award, fn (BonusAward $award) => $award->forceFill([
            'status' => BonusAwardStatus::Paid,
            'paid_at' => now(),
            'paid_by' => $actor->getKey(),
            ...RemoteActorAttribution::fields('paid', $actor),
            'payment_note' => filled($note) ? $note : null,
        ]));
    }

    public function cancel(BonusAward $award, string $reason, User $actor): BonusAward
    {
        if ($actor->cannot('bonus_awards.cancel')) {
            throw new AuthorizationException('Vous ne pouvez pas annuler un bonus.');
        }

        return $this->onValidated($award, fn (BonusAward $award) => $award->forceFill([
            'status' => BonusAwardStatus::Cancelled,
            'active_key' => null,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->getKey(),
            ...RemoteActorAttribution::fields('cancelled', $actor),
            'cancel_reason' => $reason,
        ]));
    }

    private function onValidated(BonusAward $award, callable $change): BonusAward
    {
        return DB::transaction(function () use ($award, $change): BonusAward {
            $award = BonusAward::query()->lockForUpdate()->findOrFail($award->getKey());

            if ($award->status !== BonusAwardStatus::Validated) {
                throw ValidationException::withMessages(['award' => $award->status === BonusAwardStatus::Paid
                    ? 'Ce bonus est déjà versé : il ne se modifie plus.'
                    : 'Ce bonus est annulé.']);
            }

            $change($award);
            $award->save();

            return $award;
        });
    }
}
