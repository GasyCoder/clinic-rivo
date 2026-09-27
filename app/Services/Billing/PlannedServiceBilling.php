<?php

namespace App\Services\Billing;

use App\Enums\BillableItemStatus;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\EpisodeServiceRequest;
use App\Models\ImagingRequestItem;
use App\Models\LabRequestItem;
use App\Models\MaternityProcedure;

/**
 * ADR-109 — un examen déjà demandé à la Réception n'est pas refacturé.
 *
 * Le cas réel : Mme R. arrive pour une « Échographie obstétricale ». La
 * Réception la planifie et la facture (ADR-068) — 50 000 Ar, déjà portés sur
 * une facture. Le médecin la reçoit, et l'étape Paraclinique lui demande de
 * chercher l'examen dans le catalogue pour en créer la demande. Depuis
 * l'ADR-105, cette création facture, avec une clé dérivée de la ligne de
 * demande : **un second `BillableItem` pour le même examen**.
 *
 * `RecordBillableItemAction` sait déjà ne pas refacturer une prestation
 * planifiée (ADR-054) — mais uniquement quand l'appelant ne lui impose pas
 * sa propre clé. L'ADR-105 en imposait une systématiquement, ce qui
 * court-circuitait la garde.
 *
 * Ce service répond à la seule question qui manquait : *cet examen a-t-il
 * déjà été facturé à l'arrivée, et cette facturation est-elle encore
 * disponible ?* Une prestation déjà rattachée à une autre ligne de demande
 * n'est jamais réutilisée — un second examen réellement demandé est un
 * second acte, et il se facture.
 */
class PlannedServiceBilling
{
    /**
     * La prestation déjà facturée par la Réception pour cet examen, si elle
     * existe et n'a pas encore été consommée par une demande paraclinique.
     */
    public function unconsumedFor(Episode $episode, CatalogItem $catalogItem): ?BillableItem
    {
        $planned = EpisodeServiceRequest::query()
            ->where('episode_id', $episode->getKey())
            ->where('catalog_item_id', $catalogItem->getKey())
            ->exists();

        if (! $planned) {
            return null;
        }

        return BillableItem::query()
            ->where('episode_id', $episode->getKey())
            ->where('catalog_item_id', $catalogItem->getKey())
            // Une prestation annulée ne paie plus rien : la refacturer est
            // alors le comportement juste.
            ->whereIn('status', [
                BillableItemStatus::Pending->value,
                BillableItemStatus::Invoiced->value,
            ])
            ->whereNotIn('id', $this->alreadyLinkedIds($episode))
            ->orderBy('id')
            ->first();
    }

    /**
     * Les prestations déjà portées par une ligne de demande paraclinique ou un acte Maternité.
     *
     * Sans cela, deux échographies demandées successivement pointeraient
     * toutes deux la facturation de la Réception, et la seconde ne serait
     * jamais facturée.
     *
     * @return array<int, int>
     */
    private function alreadyLinkedIds(Episode $episode): array
    {
        $lab = LabRequestItem::query()
            ->whereNotNull('billable_item_id')
            // ADR-163 — une demande retirée libère ce qu'elle avait rattaché,
            // comme un acte Maternité retiré : l'examen redemandé ensuite
            // reprend la prestation de la Réception au lieu d'en créer une seconde.
            ->whereHas('labRequest', fn ($query) => $query->where('episode_id', $episode->getKey())->whereNull('cancelled_at'))
            ->pluck('billable_item_id');

        $imaging = ImagingRequestItem::query()
            ->whereNotNull('billable_item_id')
            ->whereHas('imagingRequest', fn ($query) => $query->where('episode_id', $episode->getKey())->whereNull('cancelled_at'))
            ->pluck('billable_item_id');

        // Un acte Maternité déjà rattaché à la facturation de la Réception la
        // « consomme » : un second acte identique est un second acte, et il se
        // facture (ADR-141). Un acte retiré la libère.
        $maternity = MaternityProcedure::query()
            ->whereNotNull('billable_item_id')
            ->whereHas('record', fn ($query) => $query->where('episode_id', $episode->getKey()))
            ->pluck('billable_item_id');

        return $lab->merge($imaging)->merge($maternity)->unique()->values()->all();
    }
}
