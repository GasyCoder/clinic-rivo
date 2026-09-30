<?php

namespace App\Services\Notifications;

use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Models\Episode;
use App\Models\PharmacyStockAlert;
use App\Models\User;
use App\Enums\StaffDebtStatus;
use App\Models\StaffDebt;
use App\Services\StaffAccess\StaffAccessWatcher;
use App\Services\StaffDebts\StaffDebtWatcher;

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
        // Le portail n'a ni passages ni stock : ce qui l'attend, ce sont les accès
        // du personnel à créer sur les sites (ADR-197).
        $items = collect(config('rivo.site.type') === 'admin'
            ? [$this->staffAccess($user), $this->staffDebtsToDecide($user)]
            : [$this->settlements($user), $this->stockAlerts($user), $this->staffDebtsToDisburse($user)]
        )->filter()->values()->all();

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
     * ADR-197 — portail : les employés des sites qui attendent leur accès, tels que
     * la dernière lecture des sites les a comptés (jamais une lecture des sites à
     * l'ouverture de la cloche).
     *
     * @return array<string, mixed>|null
     */
    private function staffAccess(User $user): ?array
    {
        if (! $user->can('staff_access.view')) {
            return null;
        }

        $count = array_sum(StaffAccessWatcher::pendingCounts());

        return $count === 0 ? null : [
            'key' => 'staff_access',
            'label' => 'Accès à créer',
            'description' => 'Des employés ajoutés par le RH attendent leur compte et leur adresse.',
            'count' => $count,
            'url' => '/super-admin/staff-access',
            'action' => 'Ouvrir l’accès du personnel',
            'icon' => 'user-plus',
            'tone' => 'primary',
        ];
    }

    /**
     * ADR-228 — portail : les demandes de dette que le DG n'a pas encore décidées, telles
     * que la dernière lecture des sites les a comptées. Un seul site concerné : sa liste ;
     * plusieurs : les notifications de cette catégorie, une par demande.
     *
     * @return array<string, mixed>|null
     */
    private function staffDebtsToDecide(User $user): ?array
    {
        if (! $user->can(StaffDebtWatcher::PERMISSION)) {
            return null;
        }

        $counts = array_filter(StaffDebtWatcher::pendingCounts());
        $count = array_sum($counts);
        if ($count === 0) {
            return null;
        }

        $url = count($counts) === 1
            ? '/super-admin/sites/'.rawurlencode((string) array_key_first($counts)).'/rh/dettes?vue=a-decider'
            : '/notifications?category=staff_debts';

        return [
            'key' => 'staff_debts',
            'label' => 'Dette'.($count > 1 ? 's' : '').' à décider',
            'description' => 'Des membres du personnel demandent une dette : à accorder, ajuster ou refuser.',
            'count' => $count,
            'url' => $url,
            'action' => 'Ouvrir les demandes',
            'icon' => 'hand-coins',
            'tone' => 'warning',
        ];
    }

    /**
     * ADR-228 — sur le site : les dettes accordées par le DG que le RH doit verser.
     *
     * @return array<string, mixed>|null
     */
    private function staffDebtsToDisburse(User $user): ?array
    {
        if (! $user->can('staff_debts.disburse')) {
            return null;
        }

        $count = StaffDebt::query()->where('status', StaffDebtStatus::Approved->value)->count();

        return $count === 0 ? null : [
            'key' => 'staff_debts',
            'label' => 'Dette'.($count > 1 ? 's' : '').' à verser',
            'description' => 'Accordées par le DG : à remettre hors RIVO, puis à marquer versées.',
            'count' => $count,
            'url' => '/administration/dettes?vue=a-verser',
            'action' => 'Ouvrir les dettes',
            'icon' => 'hand-coins',
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
