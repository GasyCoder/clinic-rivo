<?php

namespace App\Actions\Reception;

use App\Models\PatientReferral;
use App\Models\User;
use App\Support\Authorization\RemoteActorAttribution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-212 — le cadeau remis à la personne qui a recommandé la clinique : une
 * trace (qui, quand, une note), sans aucun effet financier ni de stock. Il ne
 * se remet qu'une fois.
 */
class MarkReferralGiftGivenAction
{
    public function execute(PatientReferral $referral, ?string $note, User $actor): PatientReferral
    {
        if ($actor->cannot('patient_referrals.gift')) {
            throw new AuthorizationException('Vous ne pouvez pas marquer un cadeau remis.');
        }

        return DB::transaction(function () use ($referral, $note, $actor): PatientReferral {
            $referral = PatientReferral::query()->lockForUpdate()->findOrFail($referral->getKey());

            if ($referral->gift_given_at !== null) {
                throw ValidationException::withMessages(['gift' => 'Le cadeau de cette recommandation est déjà remis.']);
            }

            $referral->forceFill([
                'gift_given_at' => now(),
                'gift_given_by' => $actor->getKey(),
                ...RemoteActorAttribution::fields('gift_given', $actor),
                'gift_note' => filled($note) ? $note : null,
            ])->save();

            return $referral;
        });
    }
}
