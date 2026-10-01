<?php

namespace App\Actions\Laboratory;

use App\Enums\BillableItemStatus;
use App\Models\BillableItem;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Services\Billing\ClinicalActBiller;
use App\Services\Billing\ParaclinicalBillingRelease;
use App\Services\Catalog\CatalogActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * ADR-220 — sortir une demande d'analyses de la corbeille.
 *
 * La demande revient telle qu'elle était, analyses et saisies comprises. Ce que
 * la mise à la corbeille avait annulé au compte du patient y revient : chaque
 * analyse dont la facturation propre avait été annulée est facturée de nouveau
 * (clé de la ligne suivie d'un rang — la première clé désigne l'élément annulé,
 * qui reste dans l'historique). Un échec de facturation n'empêche jamais la
 * restauration : l'analyse se signale « non facturée » à la Réception (ADR-103).
 *
 * Refusée quand la même analyse a été redemandée entre-temps pour ce passage :
 * restaurer ferait deux fois le même examen.
 *
 * Appelée dans la transaction de la Corbeille (TrashDirectory).
 */
class RestoreLabRequestAction
{
    public const PERMISSION = 'laboratory_orders.restore';

    public function __construct(private readonly ClinicalActBiller $biller) {}

    public function execute(LabRequest $request, CatalogActor $actor): LabRequest
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Restaurer une demande d’analyses demande le droit « laboratory_orders.restore ».');
        }

        $request->load(['items.billableItem', 'items.catalogItem', 'episode', 'requestedBy']);

        $duplicates = LabRequestItem::query()
            ->whereIn('catalog_item_id', $request->items->pluck('catalog_item_id'))
            ->whereHas('labRequest', fn ($query) => $query
                ->where('episode_id', $request->episode_id)
                ->whereNull('cancelled_at')
                ->whereKeyNot($request->getKey()))
            ->pluck('catalog_item_name_snapshot')
            ->unique();

        if ($duplicates->isNotEmpty()) {
            throw ValidationException::withMessages([
                'uuid' => 'Déjà redemandé pour ce passage : '.$duplicates->implode(', ').'. Restaurer ferait deux fois le même examen.',
            ]);
        }

        $request->restore();

        // La facturation revient au nom de qui restaure, ou — depuis le portail,
        // sans compte local — de qui avait fait la demande.
        $billingActor = $actor->user() ?? $request->requestedBy;

        if ($billingActor !== null && $request->episode !== null) {
            foreach ($request->items as $line) {
                $billable = $line->billableItem;

                if ($billable?->status !== BillableItemStatus::Cancelled
                    || ! ParaclinicalBillingRelease::isOwnKey($line, $billable->idempotency_key)
                    || $line->catalogItem === null) {
                    continue;
                }

                $rank = BillableItem::query()
                    ->where('idempotency_key', 'like', ParaclinicalBillingRelease::ownKey($line).'%')
                    ->count();

                $again = $this->biller->bill(
                    $request->episode,
                    $line->catalogItem,
                    ParaclinicalBillingRelease::ownKey($line).':'.$rank,
                    $billingActor,
                    $line,
                );

                if ($again) {
                    $line->update(['billable_item_id' => $again->getKey()]);
                }
            }
        }

        return $request->fresh('items');
    }
}
