<?php

namespace App\Actions\Hospitalization;

use App\Enums\EpisodeMedicalStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Enums\HospitalStayStatus;
use App\Models\HospitalStay;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-113 — le médecin retire sa demande d'hospitalisation (changement de
 * conduite à tenir, ADR-084) : le séjour ouvert automatiquement est annulé,
 * jamais supprimé.
 *
 * Refusé dès que la fiche de régime a commencé : le patient a alors
 * réellement séjourné, et c'est la sortie médicale qui termine le séjour.
 */
class CancelHospitalStayAction
{
    public function execute(HospitalStay $stay, string $reason, User $actor): void
    {
        $locked = HospitalStay::query()->with(['episode', 'episodeOrientation'])->lockForUpdate()->findOrFail($stay->getKey());

        if (! $locked->isActive()) {
            return;
        }

        if ($locked->dietEntries()->exists()) {
            throw ValidationException::withMessages([
                'orientation' => 'Le patient est déjà hospitalisé et sa fiche de régime a commencé : terminez le séjour par une sortie médicale.',
            ]);
        }

        $locked->update([
            'status' => HospitalStayStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->getKey(),
            'cancellation_reason' => mb_substr($reason, 0, 500),
            'active_key' => null,
        ]);

        // L'orientation a été prise en charge par l'admission automatique :
        // `EpisodeOrientation::cancel()` n'accepte qu'une orientation en
        // attente, d'où l'écriture directe, limitée à ce seul cas.
        $orientation = $locked->episodeOrientation;

        if ($orientation && $orientation->status === EpisodeOrientationStatus::InProgress) {
            $orientation->forceFill([
                'status' => EpisodeOrientationStatus::Cancelled,
                'completed_by' => $actor->getKey(),
                'completed_at' => now(),
                'active_key' => null,
            ])->save();
        }

        if ($locked->episode->medical_status === EpisodeMedicalStatus::Hospitalized) {
            $locked->episode->update(['medical_status' => EpisodeMedicalStatus::InCare]);
        }
    }
}
