<?php

namespace App\Support\Hospitalization;

use App\Enums\EpisodeOrientationStatus;
use App\Enums\EpisodeStatus;
use App\Enums\HospitalStayStatus;
use App\Models\Episode;
use App\Models\EpisodeOrientation;
use App\Models\HospitalStay;
use Illuminate\Validation\ValidationException;

/**
 * ADR-162 — d'où part une demande écrite depuis le séjour.
 *
 * Une demande de consultation vérifie que la consultation est encore active ;
 * une demande du séjour vérifie que le patient est encore au lit. C'est la
 * seule différence : tout ce qui suit (réservation FEFO, facturation,
 * doublons, orientation vers le service) reste celui des actions existantes.
 * La règle est écrite ici une fois, pour que les cinq demandes du séjour ne
 * la recopient pas chacune à sa façon.
 */
final class StayOrderContext
{
    private function __construct(
        public readonly HospitalStay $stay,
        public readonly Episode $episode,
        public readonly EpisodeOrientation $orientation,
    ) {}

    /** À appeler dans la transaction de l'action : verrouille le séjour et son passage. */
    public static function lock(HospitalStay $stay, string $errorKey): self
    {
        $locked = HospitalStay::query()->lockForUpdate()->findOrFail($stay->getKey());

        if ($locked->status !== HospitalStayStatus::Active) {
            throw ValidationException::withMessages([
                $errorKey => 'Ce séjour est terminé : le patient n’est plus au lit.',
            ]);
        }

        $episode = Episode::query()->lockForUpdate()->findOrFail($locked->episode_id);

        if ($episode->status !== EpisodeStatus::Open) {
            throw ValidationException::withMessages([$errorKey => 'Ce passage est clos.']);
        }

        $orientation = EpisodeOrientation::query()->lockForUpdate()->findOrFail($locked->episode_orientation_id);

        if ($orientation->status !== EpisodeOrientationStatus::InProgress) {
            throw ValidationException::withMessages([$errorKey => 'La prise en charge du séjour n’est plus en cours.']);
        }

        return new self($locked, $episode, $orientation);
    }
}
