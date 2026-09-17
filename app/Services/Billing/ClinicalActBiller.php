<?php

namespace App\Services\Billing;

use App\Actions\Billing\AttachBillableItemToUnpaidInvoiceAction;
use App\Actions\Billing\RecordBillableItemAction;
use App\Enums\BillableItemStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillableItem;
use App\Models\CatalogItem;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * ADR-105 — porter un acte clinique au compte du patient, sans jamais
 * pouvoir l'empêcher d'avoir lieu.
 *
 * Deux règles déjà posées ailleurs et recopiées jusqu'ici à chaque nouvel
 * appelant (ADR-054 pour un acte Soins, ADR-072 pour un consommable) :
 *
 *  - l'élément rejoint la facture du passage tant qu'elle n'a reçu aucun
 *    encaissement, jamais une facture déjà réglée ou couverte ;
 *  - un échec financier — tarif absent, contexte du passage non résolu,
 *    politique Personnel non classifiée — n'annule jamais l'acte clinique
 *    déjà enregistré. La régularisation appartient à la Réception.
 *
 * Les écrire une troisième fois les aurait fait diverger : c'est le défaut
 * que l'ADR-098 relève partout où une même règle vit à plusieurs endroits.
 */
class ClinicalActBiller
{
    public function __construct(
        private readonly RecordBillableItemAction $recordBillableItem,
        private readonly AttachBillableItemToUnpaidInvoiceAction $attachToUnpaidInvoice,
    ) {}

    /**
     * @param  string  $idempotencyKey  dérivé de l'UUID de la ligne source :
     *   une relance ne facture jamais deux fois le même acte.
     */
    public function bill(
        Episode $episode,
        CatalogItem $catalogItem,
        string $idempotencyKey,
        User $actor,
        ?Model $source = null,
        int|float|string $quantity = 1,
    ): ?BillableItem {
        if (! $catalogItem->billable) {
            return null;
        }

        try {
            $billableItem = $this->recordBillableItem->execute($episode, [
                'catalog_item_uuid' => $catalogItem->uuid,
                'quantity' => $quantity,
                'idempotency_key' => $idempotencyKey,
            ], $actor, $source);
        } catch (ValidationException) {
            return null;
        }

        if ($billableItem->status === BillableItemStatus::Pending) {
            $unpaidInvoice = Invoice::query()
                ->where('episode_id', $episode->getKey())
                ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Validated->value])
                ->where('paid_amount', 0)
                ->latest()
                ->first();

            if ($unpaidInvoice) {
                $this->attachToUnpaidInvoice->execute($unpaidInvoice, $billableItem);
            }
        }

        return $billableItem;
    }
}
