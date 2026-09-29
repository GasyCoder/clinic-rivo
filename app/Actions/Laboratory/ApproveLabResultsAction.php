<?php

namespace App\Actions\Laboratory;

use App\Models\LabRequest;
use App\Models\LabRequestItem;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Laboratory\LabResultAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-216, amendement quater — le médecin relit le résultat reçu, puis le valide.
 *
 * Le laboratoire termine et envoie ; le résultat arrive au médecin « Terminé · à
 * valider ». Il l'ouvre, le relit (et le compte rendu), puis clique « Valider » :
 * l'analyse est validée, à son nom et à son heure, et la Réception peut alors la
 * voir et remettre le compte rendu au patient.
 *
 * Tout compte qui détient `laboratory_results.approve` peut valider — le droit se
 * règle depuis « Rôles & permissions », aucun rôle n'est codé en dur (ADR-152) —,
 * pourvu que le résultat ne soit pas adressé à un confrère qu'il n'a pas ouvert.
 *
 * Tout ou rien : une analyse choisie qui n'attend pas de validation (pas envoyée,
 * reprise pour être refaite, déjà validée) n'en laisse valider aucune. Sans liste,
 * toutes celles qui attendent sont validées.
 */
class ApproveLabResultsAction
{
    public const PERMISSION = 'laboratory_results.approve';

    public function __construct(
        private readonly LabResultAccess $access,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<string>  $itemUuids  vide : toutes celles qui attendent
     * @return int le nombre d'analyses validées
     */
    public function execute(LabRequest $request, array $itemUuids, User $actor): int
    {
        if ($actor->cannot(self::PERMISSION)) {
            throw new AuthorizationException('Valider un résultat d’analyse demande le droit « '.self::PERMISSION.' ».');
        }

        return DB::transaction(function () use ($request, $itemUuids, $actor): int {
            $locked = LabRequest::query()->lockForUpdate()->findOrFail($request->getKey());

            if ($locked->cancelled_at !== null) {
                throw ValidationException::withMessages(['items' => 'Cette demande a été retirée : ses résultats ne se valident plus.']);
            }
            if ($this->access->sealed($locked, $actor)) {
                throw new AuthorizationException('Ces résultats sont adressés à un confrère : ouvrez-les d’abord.');
            }

            $uuids = array_values(array_unique(array_filter($itemUuids)));
            $items = $locked->items()->orderBy('id')->lockForUpdate()
                ->when($uuids !== [], fn ($query) => $query->whereIn('uuid', $uuids))
                ->get();

            if ($uuids !== [] && $items->count() !== count($uuids)) {
                throw ValidationException::withMessages(['items' => 'Une analyse choisie n’appartient pas à cette demande.']);
            }

            if ($uuids === []) {
                $items = $items->filter(fn (LabRequestItem $item) => $item->awaitsApproval())->values();
                if ($items->isEmpty()) {
                    throw ValidationException::withMessages(['items' => 'Aucun résultat n’attend votre validation sur cette demande.']);
                }
            }

            foreach ($items as $item) {
                if ($item->awaitsApproval()) {
                    continue;
                }
                $name = $item->catalog_item_name_snapshot;
                throw ValidationException::withMessages(['items' => match (true) {
                    $item->isApproved() => "« {$name} » est déjà validée.",
                    $item->isDelivered() => "« {$name} » est reprise par le laboratoire : elle se validera une fois renvoyée.",
                    default => "« {$name} » n’a pas encore été envoyée par le laboratoire.",
                }]);
            }

            $now = now();
            LabRequestItem::query()->whereKey($items->modelKeys())->update([
                'approved_at' => $now,
                'approved_by' => $actor->getKey(),
                'updated_at' => $now,
            ]);

            $this->auditor->record(
                'laboratory.results.approve',
                $locked,
                ['analyses' => $items->pluck('catalog_item_name_snapshot')->all(), 'approved_at' => $now->toIso8601String()],
                ['analyses' => $items->pluck('catalog_item_name_snapshot')->all(), 'approved_at' => null],
                module: 'laboratory',
                actor: $actor,
            );

            return $items->count();
        });
    }
}
