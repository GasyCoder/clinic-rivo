<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\MedicalRequestStatus;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use App\Models\MedicalReferral;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-163 — retirer un transfert demandé depuis le séjour, tant que le patient
 * n'est pas parti.
 *
 * Le transfert est une **demande** (ADR-161) : le séjour ne se termine qu'au
 * départ constaté dans Transferts. Avant ce départ, le médecin peut changer
 * d'avis — le patient s'améliore, l'établissement refuse. La demande reste en
 * base, annulée, avec son auteur, sa date et le motif (ADR-010) ; le patient
 * n'a jamais quitté son lit, le séjour continue.
 *
 * Après le départ, il n'y a plus rien à annuler : le séjour est terminé.
 */
class CancelStayReferralAction
{
    public const DEFAULT_REASON = 'Transfert annulé depuis le séjour.';

    public function __construct(private readonly Auditor $auditor) {}

    public function execute(HospitalStay $stay, MedicalReferral $referral, ?string $reason, User $actor): MedicalReferral
    {
        return DB::transaction(function () use ($stay, $referral, $reason, $actor): MedicalReferral {
            $locked = MedicalReferral::query()->with('episode')->lockForUpdate()->findOrFail($referral->getKey());

            if ($locked->hospital_stay_id !== $stay->getKey()) {
                throw ValidationException::withMessages(['referral' => 'Ce transfert n’a pas été demandé depuis ce séjour.']);
            }

            if ($locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['referral' => 'Ce passage est clos.']);
            }

            if ($locked->departed_at !== null) {
                throw ValidationException::withMessages(['referral' => 'Le patient est déjà parti : le transfert est effectué, il ne s’annule plus.']);
            }

            if ($locked->status !== MedicalRequestStatus::Requested) {
                throw ValidationException::withMessages(['referral' => 'Ce transfert a déjà été annulé.']);
            }

            $reason = filled($reason) ? trim($reason) : self::DEFAULT_REASON;

            $locked->update([
                'status' => MedicalRequestStatus::Cancelled,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 500),
            ]);

            $orientation = $locked->episode_orientation_id
                ? EpisodeOrientation::query()->lockForUpdate()->find($locked->episode_orientation_id)
                : null;

            if ($orientation?->status === EpisodeOrientationStatus::Pending) {
                $orientation->cancel($actor);
            }

            $this->auditor->record(
                'hospitalization.referral.cancel',
                entity: $locked,
                oldValues: ['status' => MedicalRequestStatus::Requested->value],
                newValues: ['status' => MedicalRequestStatus::Cancelled->value, 'reason' => $reason, 'hospital_stay' => $stay->uuid],
            );

            return $locked->fresh();
        });
    }
}
