<?php

namespace App\Actions\Reception;

use App\Actions\Billing\CreateInvoiceAction;
use App\Actions\Billing\ValidateInvoiceAction;
use App\Enums\BillableItemStatus;
use App\Enums\EpisodeAdministrativeStatus;
use App\Enums\EpisodeStatus;
use App\Models\BillableItem;
use App\Models\Episode;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * ADR-090 (amendement du 2026-09-20) — porter sur une facture les prestations
 * d'un passage qui n'en ont encore aucune.
 *
 * La sortie administrative est refusée tant qu'il en reste
 * (`RecordAdministrativeExitAction`) : ceci est le chemin pour lever ce refus,
 * pour un passage (fenêtre de sortie) comme pour plusieurs (sélection
 * multiple). Il compose deux actions déjà en place — `CreateInvoiceAction`,
 * puis `ValidateInvoiceAction` pour qui a `billing.validate` — sans aucune règle
 * nouvelle. Les prestations sont relues ici, jamais reçues du navigateur : une
 * liste envoyée par l'écran pourrait avoir vieilli, ou être forgée.
 *
 * Sans `billing.validate`, la facture reste en brouillon : elle ne peut alors
 * pas encore être encaissée. Ce geste n'encaisse rien (ADR-012).
 */
class InvoicePendingPrestationsAction
{
    public function __construct(
        private readonly CreateInvoiceAction $createInvoice,
        private readonly ValidateInvoiceAction $validateInvoice,
    ) {}

    /**
     * @return array{invoice: Invoice, validated: bool}|null null si rien n'attend d'être facturé
     *
     * @throws ValidationException le passage n'est plus en attente de règlement
     */
    public function execute(Episode $episode, User $actor): ?array
    {
        if ($episode->status !== EpisodeStatus::Open
            || $episode->administrative_status !== EpisodeAdministrativeStatus::PendingSettlement) {
            throw ValidationException::withMessages([
                'exit_type' => 'Ce passage n’est plus en attente de règlement : ses prestations ne se facturent plus d’ici.',
            ]);
        }

        $uuids = BillableItem::query()
            ->where('episode_id', $episode->getKey())
            ->where('status', BillableItemStatus::Pending->value)
            ->pluck('uuid')
            ->all();

        if ($uuids === []) {
            return null;
        }

        $invoice = $this->createInvoice->execute($episode->patient, [
            'episode_uuid' => $episode->uuid,
            'billable_item_uuids' => $uuids,
        ], $actor);

        if ($actor->can('billing.validate')) {
            return ['invoice' => $this->validateInvoice->execute($invoice, $actor), 'validated' => true];
        }

        return ['invoice' => $invoice, 'validated' => false];
    }
}
