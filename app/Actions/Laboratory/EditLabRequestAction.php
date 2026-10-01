<?php

namespace App\Actions\Laboratory;

use App\Enums\CatalogItemType;
use App\Enums\CatalogModule;
use App\Models\CatalogItem;
use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Billing\ClinicalActBiller;
use App\Services\Billing\ParaclinicalBillingRelease;
use App\Services\Billing\PlannedServiceBilling;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-220 — corriger une demande d'analyses au laboratoire : ajouter une
 * analyse, en retirer une, reprendre les renseignements cliniques.
 *
 *   ajouter      une prestation LABORATORY du catalogue, jamais deux fois la même
 *                dans la demande ; facturée comme à la demande du médecin
 *                (ADR-105), en reprenant la prestation que la Réception avait déjà
 *                facturée à l'arrivée quand elle existe (ADR-109)
 *   retirer      jamais après un envoi au médecin, jamais la dernière analyse (la
 *                demande entière part alors à la corbeille) ; motif exigé ; ce que
 *                la ligne a elle-même facturé et qui attend encore est annulé, ce
 *                qui est sur facture reste à la Caisse (ADR-012). L'analyse est
 *                mise à la corbeille de la demande : sa trace reste (ADR-009)
 *   renseigner   le renseignement clinique de la demande, repris sur la feuille et
 *                le compte rendu ; l'ancienne valeur reste à l'audit
 *
 * Une demande archivée se désarchive d'abord : la corriger la ferait revenir dans
 * le travail en cours sans que personne ne l'ait décidé.
 */
class EditLabRequestAction
{
    public const PERMISSION = 'laboratory_orders.update';

    public function __construct(
        private readonly ClinicalActBiller $biller,
        private readonly PlannedServiceBilling $plannedBilling,
        private readonly ParaclinicalBillingRelease $billing,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array<int, string>  $catalogUuids
     * @return array<int, LabRequestItem>
     */
    public function addItems(LabRequest $request, array $catalogUuids, User $actor): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $catalogUuids, $actor): array {
            $locked = $this->lockOpen($request);
            $locked->load('episode');

            $uuids = collect($catalogUuids)->filter()->unique()->values();
            $catalog = CatalogItem::query()
                ->whereIn('uuid', $uuids)
                ->where('type', CatalogItemType::Service->value)
                ->where('module', CatalogModule::Laboratory->value)
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            if ($uuids->isEmpty() || $catalog->count() !== $uuids->count()) {
                throw ValidationException::withMessages(['catalog_item_uuids' => 'Une analyse choisie n’est plus disponible au catalogue du laboratoire.']);
            }

            $present = $locked->items->pluck('catalog_item_id')->all();
            $already = $catalog->filter(fn (CatalogItem $item) => in_array($item->getKey(), $present, true));

            if ($already->isNotEmpty()) {
                throw ValidationException::withMessages(['catalog_item_uuids' => 'Déjà dans la demande : '.$already->pluck('name')->implode(', ').'.']);
            }

            $created = [];
            foreach ($uuids as $uuid) {
                $catalogItem = $catalog->get($uuid);
                $line = $locked->items()->create([
                    'catalog_item_id' => $catalogItem->getKey(),
                    'catalog_item_code_snapshot' => $catalogItem->code,
                    'catalog_item_name_snapshot' => $catalogItem->name,
                ]);

                $billable = $this->plannedBilling->unconsumedFor($locked->episode, $catalogItem)
                    ?? $this->biller->bill($locked->episode, $catalogItem, ParaclinicalBillingRelease::ownKey($line), $actor, $line);

                if ($billable) {
                    $line->update(['billable_item_id' => $billable->getKey()]);
                }

                $created[] = $line;
            }

            $this->auditor->record('laboratory.request.items.add', entity: $locked, newValues: [
                'items' => collect($created)->pluck('catalog_item_name_snapshot')->all(),
            ], module: 'clinical_flow', actor: $actor);

            return $created;
        });
    }

    public function removeItem(LabRequestItem $item, ?string $reason, User $actor): LabRequestItem
    {
        $this->authorize($actor);

        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Indiquez pourquoi l’analyse est retirée de la demande.']);
        }

        return DB::transaction(function () use ($item, $reason, $actor): LabRequestItem {
            $locked = $this->lockOpen($item->labRequest()->firstOrFail());
            /** @var LabRequestItem|null $line */
            $line = $locked->items->firstWhere('id', $item->getKey());

            if ($line === null) {
                throw ValidationException::withMessages(['item' => 'Cette analyse n’est plus dans la demande.']);
            }

            if ($line->sent_at !== null) {
                throw ValidationException::withMessages(['item' => 'Cette analyse a déjà été envoyée au médecin : elle ne se retire pas.']);
            }

            if ($locked->items->count() === 1) {
                throw ValidationException::withMessages(['item' => 'C’est la dernière analyse de la demande : mettez plutôt la demande à la corbeille.']);
            }

            $this->billing->releaseLine($line, $actor, 'Analyse retirée de la demande par le laboratoire — '.$reason);

            $line->delete_reason = mb_substr($reason, 0, 1000);
            $line->delete();

            return $line;
        });
    }

    public function updateNotes(LabRequest $request, ?string $notes, User $actor): LabRequest
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($request, $notes, $actor): LabRequest {
            $locked = $this->lockOpen($request);
            $notes = filled($notes) ? trim((string) $notes) : null;

            if ($notes === $locked->notes) {
                throw ValidationException::withMessages(['notes' => 'Les renseignements n’ont pas changé.']);
            }

            // `Auditable` garde l'ancienne et la nouvelle valeur.
            $locked->update(['notes' => $notes]);

            return $locked;
        });
    }

    private function lockOpen(LabRequest $request): LabRequest
    {
        $locked = LabRequestGuard::lock($request);

        if ($locked->isLabArchived()) {
            throw ValidationException::withMessages(['request' => 'Cette demande est archivée : désarchivez-la d’abord pour la corriger.']);
        }

        return $locked;
    }

    private function authorize(User $actor): void
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Modifier une demande demande le droit « laboratory_orders.update ».');
        }
    }
}
