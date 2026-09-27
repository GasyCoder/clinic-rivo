<?php

namespace App\Support;

use App\Models\CareOrder;
use App\Models\CareOrderItem;
use Illuminate\Support\Collection;

/**
 * ADR-118 — ce qu'une orientation Soins demandée par le médecin contient :
 * qui l'a demandée, ce que le médecin a décidé pour la suite, et ses actes.
 *
 * Écrit une seule fois pour les deux écrans qui montrent une orientation
 * Soins : le parcours du passage (ADR-117) et la file Soins. Les deux
 * affichaient « Soins » sans dire de quelle demande il s'agissait, et deux
 * demandes du même passage se lisaient comme deux passages identiques. Une
 * règle recopiée à deux endroits finit par diverger : le parcours et la file
 * doivent dire la même chose de la même demande.
 *
 * Plusieurs demandes peuvent partager une même orientation :
 * `CreateEpisodeOrientationAction` réutilise l'orientation Soins encore
 * active. Toutes les fonctions reçoivent donc une collection de demandes,
 * jamais une seule.
 */
final class CareRequestSummary
{
    /**
     * Ce que le médecin a décidé pour la suite des soins — l'information dont
     * le personnel manquait : sans elle, deux orientations « Soins » ne disent
     * pas si le patient repasse chez le médecin ou part au règlement.
     *
     * Si l'une des demandes attend le patient en Médecine, l'orientation
     * l'annonce : le médecin le reverra, et ne pas le dire serait la seule
     * erreur qui coûte.
     *
     * @param  Collection<int, CareOrder>  $orders
     * @return array{code: string, label: string}
     */
    public static function followUp(Collection $orders): array
    {
        // ADR-162 — des soins demandés depuis le séjour : le patient retourne
        // à son lit, il ne « sort » pas après les soins.
        if ($orders->isNotEmpty() && $orders->every(fn (CareOrder $order) => $order->hospital_stay_id !== null)) {
            return ['code' => 'STAY_IN_BED', 'label' => 'Le patient reste hospitalisé après les soins'];
        }

        return $orders->contains(fn (CareOrder $order) => $order->requires_return_to_medicine)
            ? ['code' => 'RETURN_TO_MEDICINE', 'label' => 'Retour en Médecine prévu après les soins']
            : ['code' => 'DIRECT_EXIT', 'label' => 'Sortie directe après les soins (sans retour en Médecine)'];
    }

    /**
     * Les actes demandés et où ils en sont. L'état se lit sur les actes
     * réellement enregistrés par les Soins, jamais sur un drapeau saisi
     * (ADR-032) ; les demandes doivent donc porter `items.careRecordProcedures`.
     *
     * @param  Collection<int, CareOrder>  $orders
     * @return array<int, array{name: string, quantity: string, state: string, state_label: string}>
     */
    public static function items(Collection $orders): array
    {
        return $orders->flatMap(fn (CareOrder $order) => $order->items)->map(function (CareOrderItem $item) {
            $realized = (float) $item->careRecordProcedures->sum('quantity');
            $requested = (float) $item->quantity;

            [$state, $label] = match (true) {
                $item->isCancelled() => ['CANCELLED', 'Retiré'],
                $item->not_performed_at !== null => ['NOT_PERFORMED', 'Non réalisé'],
                $realized >= $requested => ['DONE', 'Réalisé'],
                $realized > 0 => ['PARTIAL', 'Partiellement réalisé'],
                default => ['PENDING', 'À réaliser'],
            };

            return [
                'name' => $item->catalog_item_name_snapshot,
                'quantity' => number_format($requested, 2, '.', ''),
                'state' => $state,
                'state_label' => $label,
            ];
        })->values()->all();
    }

    /**
     * Le résumé de chaque orientation Soins qui porte une demande du médecin,
     * en un nombre constant de requêtes — la file en présente vingt par page.
     * Une orientation sans demande derrière elle (arrivée, urgence) n'a pas
     * d'entrée : elle n'a pas de suite à annoncer, et ne l'invente pas.
     *
     * Les actes sont la donnée de `care_orders.view` ; la suite décidée et le
     * demandeur sont de l'information de routage, de même nature que
     * l'orientation elle-même.
     *
     * @param  Collection<int, int>|array<int, int>  $orientationIds
     * @return array<int, array{requested_by: array<int, string>, follow_up: array{code: string, label: string}, items: array<int, array<string, string>>}>
     */
    public static function forOrientations(Collection|array $orientationIds, bool $withItems): array
    {
        $ids = collect($orientationIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return CareOrder::query()
            ->whereIn('care_orientation_id', $ids)
            ->with('requestedBy:id,name')
            ->when($withItems, fn ($query) => $query->with('items.careRecordProcedures'))
            ->orderBy('id')
            ->get()
            ->groupBy('care_orientation_id')
            ->map(fn (Collection $orders) => [
                'requested_by' => $orders->map(fn (CareOrder $order) => $order->requestedBy?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'follow_up' => self::followUp($orders),
                'items' => $withItems ? self::items($orders) : [],
            ])
            ->all();
    }
}
