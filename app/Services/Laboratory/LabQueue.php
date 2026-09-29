<?php

namespace App\Services\Laboratory;

use App\Enums\LabItemStatus;
use App\Models\LabRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-213 — la file du Laboratoire, par demande. Chaque demande est dans une
 * seule vue, lue sur l'état de ses analyses, par ordre de priorité :
 *
 *   to_do        à traiter : pas encore commencée, ou une analyse en cours (ADR-217)
 *   to_redo      une analyse renvoyée à refaire
 *   to_validate  tout est rendu, une analyse attend d'être envoyée au médecin (ADR-216)
 *   validated    toutes les analyses sont envoyées
 *
 * Une demande retirée par le prescripteur n'y est jamais (ADR-079).
 *
 * ADR-220 — une demande rangée par le laboratoire quitte toutes les vues et vit
 * dans « Archivées » ; une demande mise à la corbeille n'est plus nulle part.
 */
class LabQueue
{
    // ADR-217 — plus de vue « À réceptionner » : une demande non commencée est à traiter.
    public const VIEWS = ['to_do', 'to_redo', 'to_validate', 'validated', 'all', 'archived'];

    public function base(): Builder
    {
        return LabRequest::query()->whereNull('cancelled_at')->whereNull('lab_archived_at')->whereHas('items');
    }

    /** ADR-220 — la base d'une vue : « Archivées » a la sienne. */
    public function query(string $view): Builder
    {
        return $view === 'archived'
            ? LabRequest::query()->whereNull('cancelled_at')->whereNotNull('lab_archived_at')->whereHas('items')
            : $this->scope($this->base(), $view);
    }

    public function scope(Builder $query, string $view): Builder
    {
        $open = [LabItemStatus::Pending->value, LabItemStatus::InProgress->value];
        $status = fn (array $values) => fn ($items) => $items->whereIn('status', $values);

        return match ($view) {
            'to_redo' => $query->whereHas('items', $status([LabItemStatus::ToRedo->value])),
            'to_do' => $query->whereDoesntHave('items', $status([LabItemStatus::ToRedo->value]))
                ->whereHas('items', $status($open)),
            'to_validate' => $query->whereDoesntHave('items', $status([...$open, LabItemStatus::ToRedo->value]))
                ->whereHas('items', $status([LabItemStatus::Completed->value])),
            'validated' => $query->whereDoesntHave('items', fn ($items) => $items->where('status', '!=', LabItemStatus::Validated->value)),
            default => $query,
        };
    }

    /** @return array<string, int> */
    public function counts(?callable $refine = null): array
    {
        $counts = [];
        foreach (self::VIEWS as $view) {
            $query = $this->query($view);
            if ($refine) {
                $refine($query);
            }
            $counts[$view] = $query->count();
        }

        return $counts;
    }

    /**
     * ADR-214 — les demandes dont une analyse est confiée à un laboratoire
     * extérieur et attend encore son résultat. Un filtre, pas une vue : la
     * demande reste dans la vue de son état.
     */
    public function sentOut(Builder $query): Builder
    {
        return $query->whereHas('items', fn ($items) => $items->whereNotNull('sent_out_at')
            ->whereIn('status', [LabItemStatus::Pending->value, LabItemStatus::InProgress->value, LabItemStatus::ToRedo->value]));
    }

    /** L'état d'une demande dans la file, lu sur ses analyses. */
    public static function stateOf(LabRequest $request): string
    {
        $statuses = $request->items->map(fn ($item) => $item->currentStatus());

        return match (true) {
            $statuses->contains(LabItemStatus::ToRedo) => 'to_redo',
            $statuses->contains(LabItemStatus::Pending) || $statuses->contains(LabItemStatus::InProgress) => 'to_do',
            $statuses->contains(LabItemStatus::Completed) => 'to_validate',
            default => 'validated',
        };
    }

    /**
     * Ce que le technicien fait de la ligne, comme dans la file de labo-vuejs :
     * « Traiter » une demande que personne n'a commencée, « Continuer » une
     * demande en cours, « Reprendre » une analyse à refaire.
     */
    public static function actionOf(LabRequest $request): string
    {
        return match (self::stateOf($request)) {
            'to_redo' => 'redo',
            'to_do' => $request->received_at === null && $request->items->every(fn ($item) => $item->currentStatus() === LabItemStatus::Pending) ? 'start' : 'continue',
            'to_validate' => 'send',
            default => 'open',
        };
    }
}
