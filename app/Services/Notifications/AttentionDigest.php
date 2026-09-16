<?php

namespace App\Services\Notifications;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\PharmacyStockAlert;
use App\Models\User;

/**
 * Ce qui attend réellement quelqu'un, agrégé pour l'en-tête.
 *
 * RIVO n'a pas de table `notifications` et rien n'enregistre qu'un compte a
 * lu quoi que ce soit. Afficher « 3 non lues » inventerait donc une lecture
 * que personne n'a faite — le même refus que pour les résultats d'examens
 * (`ParaclinicalRequestDirectoryController`).
 *
 * Ce panneau n'annonce pas des messages : il compte des **faits en cours**,
 * déjà calculés par les écrans qui les possèdent. Un point disparaît quand
 * le travail est fait, jamais quand on l'a regardé.
 *
 * Chaque ligne est filtrée par la permission qui possède réellement la
 * donnée : un compte qui n'a pas le droit de voir une file ne doit pas
 * apprendre sa taille par un compteur.
 */
class AttentionDigest
{
    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function forUser(User $user): array
    {
        $items = collect([
            $this->settlements($user),
            $this->stockAlerts($user),
        ])->filter()->values()->all();

        return [
            'items' => $items,
            'total' => array_sum(array_column($items, 'count')),
        ];
    }

    /**
     * Les passages dont la partie clinique est finie et qui attendent la
     * Réception (ADR-090).
     *
     * @return array<string, mixed>|null
     */
    private function settlements(User $user): ?array
    {
        if (! $user->can('episodes.settlement.view')) {
            return null;
        }

        $count = Episode::query()
            ->where('status', EpisodeStatus::Open->value)
            ->where('administrative_status', EpisodeAdministrativeStatus::PendingSettlement->value)
            ->count();

        return $count === 0 ? null : [
            'key' => 'settlements',
            'label' => 'Passage'.($count > 1 ? 's' : '').' à régler',
            'description' => 'La partie clinique est terminée ; la sortie administrative reste à prononcer.',
            'count' => $count,
            'url' => '/reception/sorties',
            'action' => 'Ouvrir les sorties & règlements',
            // L'icône est nommée, jamais choisie par l'écran : deux panneaux
            // finiraient par illustrer la même source différemment.
            'icon' => 'wallet',
            'tone' => 'primary',
        ];
    }

    /**
     * Les alertes de stock non résolues, telles que la Pharmacie les tient
     * déjà (ADR-049) : aucun seuil n'est recalculé ici.
     *
     * @return array<string, mixed>|null
     */
    private function stockAlerts(User $user): ?array
    {
        if (! $user->can('stock.view')) {
            return null;
        }

        $count = PharmacyStockAlert::query()->whereNull('resolved_at')->count();

        return $count === 0 ? null : [
            'key' => 'stock',
            'label' => 'Alerte'.($count > 1 ? 's' : '').' de stock',
            'description' => 'Seuil minimal atteint ou rupture : à réapprovisionner.',
            'count' => $count,
            'url' => '/pharmacy/stock',
            'action' => 'Ouvrir le stock',
            'icon' => 'package',
            'tone' => 'warning',
        ];
    }
}
