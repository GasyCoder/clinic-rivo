<?php

namespace App\Support;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeOrientationStatus;
use App\Models\Episode;
use App\Models\EpisodeOrientation;

/**
 * La règle de l'ADR-054, pour les gestes qui **retirent** du travail.
 *
 * Retirer une visite, une demande au bloc ou un transfert peut laisser un
 * passage sans plus aucun service qui ait le patient. Il rejoint alors
 * « Sorties & règlements » (ADR-090) — sinon il resterait « en soins »
 * indéfiniment, sans que personne ne le voie.
 *
 * Un patient encore au lit n'est jamais concerné : l'orientation de son séjour
 * reste active. Un statut déjà avancé par la Réception n'est jamais ramené en
 * arrière.
 */
final class EpisodeSettlement
{
    public static function advanceWhenNoServiceLeft(Episode $episode): void
    {
        if ($episode->administrative_status !== EpisodeAdministrativeStatus::InCare) {
            return;
        }

        $serviceLeft = EpisodeOrientation::query()
            ->where('episode_id', $episode->getKey())
            ->whereIn('status', [EpisodeOrientationStatus::Pending->value, EpisodeOrientationStatus::InProgress->value])
            ->exists();

        if (! $serviceLeft) {
            $episode->update(['administrative_status' => EpisodeAdministrativeStatus::PendingSettlement]);
        }
    }
}
