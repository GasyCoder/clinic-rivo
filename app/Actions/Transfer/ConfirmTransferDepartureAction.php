<?php

namespace App\Actions\Transfer;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\HospitalStayEndReason;
use App\Enums\HospitalStayStatus;
use App\Enums\MedicalRequestStatus;
use App\Models\HospitalStay;
use App\Models\MedicalReferral;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-114 — « Transfert effectué » : le patient a réellement quitté la
 * clinique vers l'établissement destinataire.
 *
 * Jusque-là il reste en soins. Ce constat termine l'orientation Transfert,
 * porte le statut médical TRANSFERRED sur le passage, et le fait rejoindre
 * « Sorties & règlements » selon la règle de l'ADR-054 — seulement quand
 * plus aucun service n'a le patient. Il n'encaisse rien et ne fabrique
 * aucune sortie médicale : c'est un fait constaté, pas une décision.
 */
class ConfirmTransferDepartureAction
{
    public function __construct(private readonly Auditor $auditor) {}

    /** @param array<string, mixed> $data */
    public function execute(MedicalReferral $referral, array $data, User $actor): MedicalReferral
    {
        return DB::transaction(function () use ($referral, $data, $actor): MedicalReferral {
            $locked = MedicalReferral::query()
                ->with(['episode', 'episodeOrientation'])
                ->lockForUpdate()
                ->findOrFail($referral->getKey());

            if ($locked->status !== MedicalRequestStatus::Requested) {
                throw ValidationException::withMessages(['departure' => 'Cette demande de transfert a été annulée.']);
            }

            if ($locked->hasDeparted()) {
                throw ValidationException::withMessages(['departure' => 'Le départ de ce patient est déjà enregistré.']);
            }

            if ($locked->episode->status !== EpisodeStatus::Open) {
                throw ValidationException::withMessages(['departure' => 'Ce passage est déjà clos.']);
            }

            // On ne transfère pas « quelque part » : l'établissement est ce
            // que le document de transfert et le dossier doivent nommer.
            $facility = trim((string) ($data['facility'] ?? '')) ?: $locked->facility;

            if ($facility === null) {
                throw ValidationException::withMessages([
                    'facility' => 'Indiquez l’établissement destinataire avant de confirmer le départ.',
                ]);
            }

            $departedAt = $data['departed_at'] ?? now();

            $locked->update([
                'facility' => $facility,
                'departed_at' => $departedAt,
                'departed_by' => $actor->getKey(),
                'departure_notes' => trim((string) ($data['departure_notes'] ?? '')) ?: null,
            ]);

            $orientation = $locked->episodeOrientation;

            if ($orientation?->status === EpisodeOrientationStatus::Pending) {
                $orientation->accept($actor);
            }

            if ($orientation?->status === EpisodeOrientationStatus::InProgress) {
                $orientation->complete($actor);
            }

            $episode = $locked->episode;

            // ADR-161 — le patient au lit est parti : son séjour se termine ici,
            // au départ, et non à la décision. Sans cela il restait « au lit »
            // et le passage n'atteignait jamais « Sorties & règlements ».
            $stay = HospitalStay::query()
                ->where('episode_id', $episode->getKey())
                ->where('status', HospitalStayStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($stay) {
                $stay->update([
                    'status' => HospitalStayStatus::Discharged,
                    'end_reason' => HospitalStayEndReason::Transfer,
                    'discharged_at' => $departedAt,
                    'discharged_by' => $actor->getKey(),
                    'medical_referral_id' => $locked->getKey(),
                    'active_key' => null,
                ]);
                $stay->closeCurrentMovement($departedAt);

                $stayOrientation = $stay->episodeOrientation()->first();

                if ($stayOrientation?->status === EpisodeOrientationStatus::InProgress) {
                    $stayOrientation->complete($actor);
                }
            }

            $episode->medical_status = EpisodeMedicalStatus::Transferred;

            if ($episode->administrative_status === EpisodeAdministrativeStatus::InCare
                && ! $episode->orientations()
                    ->whereIn('status', [
                        EpisodeOrientationStatus::Pending->value,
                        EpisodeOrientationStatus::InProgress->value,
                    ])
                    ->exists()) {
                $episode->administrative_status = EpisodeAdministrativeStatus::PendingSettlement;
            }

            $episode->save();

            $this->auditor->record(
                'transfer.depart',
                $locked,
                ['departed_at' => (string) $departedAt, 'facility' => $facility],
                ['departed_at' => null],
                null,
                'transfer',
                $actor,
            );

            return $locked->fresh();
        });
    }
}
